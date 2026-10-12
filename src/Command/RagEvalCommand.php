<?php

namespace App\Command;

use App\Service\ChunkRetriever;
use App\Service\RagEvalTools;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mierzy jakość wyszukiwania asystenta na zestawie rag-eval/:
 *
 *  - golden.json: pytania z kotwicą — czy fragment z kotwicą trafia do 8
 *    fragmentów, które dostaje model (Trafność@8), i na którym miejscu (MRR).
 *    Osobno liczy obie „ręce” wyszukiwania: semantyczną (embeddingi) i pełnotekstową.
 *  - topics.json (--judge): prawdziwe, ogólne pytania czytelników — tu nie ma
 *    jednej dobrej odpowiedzi, więc każdy z 8 fragmentów ocenia model (0–2);
 *    oceny są zapamiętywane w judgments.json, kolejne przebiegi płacą tylko za nowe.
 *
 * Wynik przebiegu trafia do rag-eval/runs/, --compare pokazuje różnice z innym.
 * Baza jest tylko czytana.
 */
#[AsCommand(name: 'app:rag-eval', description: 'Zmierz jakość wyszukiwania asystenta na zestawie testowym')]
class RagEvalCommand extends Command
{
    private const K = 8;        // tyle fragmentów dostaje model odpowiadający
    private const DEEP = 50;    // głębokość szukania — czy reranker miałby z czego wybierać

    private const JUDGE_SYSTEM = <<<'TXT'
        Oceniasz wyniki wyszukiwarki w Dziełach Zebranych kardynała Stefana Wyszyńskiego.
        Dla każdego ponumerowanego fragmentu oceń, czy pomaga odpowiedzieć na pytanie czytelnika:
        2 = fragment wprost odpowiada na pytanie (można go zacytować w odpowiedzi),
        1 = fragment jest na temat, ale odpowiada tylko częściowo lub pośrednio,
        0 = fragment nie dotyczy pytania.
        Odpowiedz wyłącznie JSON-em: {"scores": {"<numer>": 0|1|2, ...}}
        TXT;

    public function __construct(
        private ChunkRetriever $retriever,
        private Connection $db,
        private RagEvalTools $tools,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Katalog zestawu', 'rag-eval')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Nazwa przebiegu (np. bge-m3, poldense-150m)', 'bge-m3')
            ->addOption('judge', null, InputOption::VALUE_NONE, 'Oceń też ogólne pytania z topics.json (kosztuje kilka centów)')
            ->addOption('judge-model', null, InputOption::VALUE_REQUIRED, 'Model oceniający', 'google/gemini-2.5-flash')
            ->addOption('compare', null, InputOption::VALUE_REQUIRED, 'Plik wcześniejszego przebiegu do porównania')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Tylko pytania o tych id (po przecinku)')
            ->addOption('show-misses', null, InputOption::VALUE_NONE, 'Wypisz pytania, których fragmentu nie ma w 8 wynikach');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = rtrim($input->getOption('dir'), '/');
        $golden = json_decode((string) @file_get_contents("$dir/golden.json"), true);
        if (!$golden) {
            $io->error("Brak $dir/golden.json — najpierw app:rag-eval:generate.");
            return Command::FAILURE;
        }
        $items = $golden['items'];
        if ($only = $input->getOption('only')) {
            $ids = array_map('trim', explode(',', $only));
            $items = array_values(array_filter($items, fn($i) => in_array($i['id'], $ids, true)));
        }

        $results = [];
        $t0 = microtime(true);
        $io->progressStart(count($items));
        foreach ($items as $it) {
            $results[] = $this->evalItem($it);
            $io->progressAdvance();
        }
        $io->progressFinish();
        $seconds = microtime(true) - $t0;

        $summary = $this->summarize($results);
        $this->printSummary($io, $summary, $results, count($items), $seconds);

        $topics = null;
        if ($input->getOption('judge')) {
            $topics = $this->judgeTopics($io, $dir, $input->getOption('judge-model'));
        }

        if ($input->getOption('show-misses')) {
            $rows = [];
            foreach ($results as $r) {
                if ($r['rank'] === null || $r['rank'] > self::K) {
                    $rows[] = [$r['id'], $r['type'], mb_strimwidth($r['question'], 0, 70, '…'), $r['rank'] ?? '>50', $r['sem_rank'] ?? '–', $r['fts_rank'] ?? '–'];
                }
            }
            $io->section('Pytania, których fragment nie trafił do ósemki');
            $io->table(['id', 'typ', 'pytanie', 'miejsce', 'semant.', 'pełnotekst.'], $rows);
        }

        $run = [
            'label' => $input->getOption('label'),
            'date' => date('c'),
            'questions' => count($items),
            'seconds' => round($seconds, 1),
            'summary' => $summary,
            'topics' => $topics,
            'items' => $results,
        ];
        @mkdir("$dir/runs", 0775, true);
        $file = sprintf('%s/runs/%s-%s.json', $dir, date('Ymd-Hi'), preg_replace('/[^a-z0-9._-]+/i', '-', $run['label']));
        file_put_contents($file, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        $io->writeln("Zapisano: $file");

        if ($cmp = $input->getOption('compare')) {
            $this->compare($io, json_decode((string) file_get_contents($cmp), true), $run);
        }
        if ($this->tools->spentUsd > 0) {
            $io->writeln(sprintf('Koszt ocen: %.4f USD', $this->tools->spentUsd));
        }
        return Command::SUCCESS;
    }

    private function evalItem(array $it): array
    {
        $fused = $this->retriever->retrieve($it['question'], self::DEEP);
        $rank = $this->firstRank($fused, $it);
        $docRank = null;
        foreach ($fused as $i => $c) {
            if ($c['document_id'] === $it['document_id']) {
                $docRank = $i + 1;
                break;
            }
        }
        return [
            'id' => $it['id'],
            'type' => $it['type'],
            'volume' => $it['volume'],
            'question' => $it['question'],
            'rank' => $rank,          // miejsce fragmentu z kotwicą w wynikach łączonych
            'doc_rank' => $docRank,   // miejsce pierwszego fragmentu z właściwego tekstu
            'sem_rank' => $this->firstRank($this->arm('sem', $it['question']), $it),
            'fts_rank' => $this->firstRank($this->arm('fts', $it['question']), $it),
        ];
    }

    /** Miejsce (od 1) pierwszego fragmentu zawierającego kotwicę; null = brak w wynikach. */
    private function firstRank(array $chunks, array $it): ?int
    {
        foreach ($chunks as $i => $c) {
            if (RagEvalTools::coverage($it['anchor'], $c['content']) >= RagEvalTools::MIN_COVERAGE) {
                return $i + 1;
            }
        }
        return null;
    }

    /** Jedna „ręka” wyszukiwania osobno — te same warunki co w ChunkRetriever. */
    private function arm(string $which, string $q): array
    {
        $published = "c.volume_id IN (SELECT id FROM volumes WHERE status = 'opublikowany')";
        if ($which === 'sem') {
            $emb = $this->retriever->embed($q);
            if ($emb === null) {
                return [];
            }
            return $this->db->fetchAllAssociative(
                "SELECT c.content FROM document_chunks c
                 WHERE c.embedding IS NOT NULL AND $published
                 ORDER BY c.embedding <=> :emb::vector LIMIT " . self::DEEP,
                ['emb' => '[' . implode(',', $emb) . ']']
            );
        }
        return $this->db->fetchAllAssociative(
            "SELECT c.content FROM document_chunks c
             WHERE c.search_vector @@ (websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q)) AND $published
             ORDER BY ts_rank(c.search_vector, websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q)) DESC
             LIMIT " . self::DEEP,
            ['q' => $this->retriever->ftsQuery($q)]
        );
    }

    private function summarize(array $results): array
    {
        $groups = ['wszystkie' => $results];
        foreach ($results as $r) {
            $groups['typ: ' . $r['type']][] = $r;
        }
        $out = [];
        foreach ($groups as $name => $rs) {
            $n = count($rs);
            $hit = fn(string $f, int $k) => round(100 * count(array_filter($rs, fn($r) => $r[$f] !== null && $r[$f] <= $k)) / $n, 1);
            $out[$name] = [
                'n' => $n,
                'hit1' => $hit('rank', 1),
                'hit3' => $hit('rank', 3),
                'hit8' => $hit('rank', self::K),
                'hit20' => $hit('rank', 20),
                'hit50' => $hit('rank', self::DEEP),
                'mrr8' => round(array_sum(array_map(fn($r) => $r['rank'] !== null && $r['rank'] <= self::K ? 1 / $r['rank'] : 0, $rs)) / $n, 3),
                'doc8' => $hit('doc_rank', self::K),
                'sem8' => $hit('sem_rank', self::K),
                'sem50' => $hit('sem_rank', self::DEEP),
                'fts8' => $hit('fts_rank', self::K),
                'fts50' => $hit('fts_rank', self::DEEP),
            ];
        }
        return $out;
    }

    private function printSummary(SymfonyStyle $io, array $summary, array $results, int $n, float $seconds): void
    {
        $io->section(sprintf('Pytania z kotwicą: %d (%.0f s)', $n, $seconds));
        $rows = [];
        foreach ($summary as $name => $s) {
            $rows[] = [$name, $s['n'], $s['hit1'], $s['hit3'], "<info>{$s['hit8']}</info>", $s['hit20'], $s['hit50'], $s['mrr8'], $s['doc8'], $s['sem8'], $s['fts8']];
        }
        $io->table(
            ['grupa', 'n', '@1 %', '@3 %', '@8 %', '@20 %', '@50 %', 'MRR@8', 'tekst@8 %', 'semant.@8 %', 'pełnotekst.@8 %'],
            $rows
        );
        $io->writeln(' @8 — w ilu % pytań fragment z odpowiedzią trafił do 8 fragmentów przekazywanych modelowi.');
        $io->writeln(' @20/@50 — górna granica dla rerankera; tekst@8 — trafiony choć jeden fragment z właściwego tekstu.');
    }

    /** Ocena ogólnych pytań czytelników: ile z 8 fragmentów jest na temat. */
    private function judgeTopics(SymfonyStyle $io, string $dir, string $model): ?array
    {
        $topics = json_decode((string) @file_get_contents("$dir/topics.json"), true);
        if (!$topics) {
            $io->warning("Brak $dir/topics.json — pomijam ocenę pytań ogólnych.");
            return null;
        }
        $cacheFile = "$dir/judgments.json";
        $cache = json_decode((string) @file_get_contents($cacheFile), true) ?: [];

        $rows = [];
        $all = [];
        foreach ($topics['items'] as $t) {
            $chunks = $this->retriever->retrieve($t['question'], self::K);
            $keys = array_map(fn($c) => md5(RagEvalTools::normalize($t['question']) . "\n" . RagEvalTools::normalize($c['content'])), $chunks);
            $todo = array_values(array_filter(array_keys($chunks), fn($i) => !isset($cache[$keys[$i]])));
            if ($todo) {
                $user = "Pytanie czytelnika: {$t['question']}\n";
                foreach ($todo as $i) {
                    $user .= "\n--- Fragment " . ($i + 1) . " ({$chunks[$i]['title']}) ---\n{$chunks[$i]['content']}\n";
                }
                try {
                    $r = $this->tools->llmJson($model, self::JUDGE_SYSTEM, $user);
                } catch (\RuntimeException $e) {
                    $io->error($e->getMessage());
                    return null;
                }
                foreach ($todo as $i) {
                    $s = $r['scores'][(string) ($i + 1)] ?? null;
                    if ($s !== null) {
                        $cache[$keys[$i]] = (int) $s;
                    }
                }
            }
            $scores = array_map(fn($k) => $cache[$k] ?? 0, $keys);
            $relevant = count(array_filter($scores, fn($s) => $s >= 1));
            $direct = count(array_filter($scores, fn($s) => $s === 2));
            $all[] = ['id' => $t['id'], 'question' => $t['question'], 'relevant' => $relevant, 'direct' => $direct, 'scores' => $scores];
            $rows[] = [$t['id'], mb_strimwidth($t['question'], 0, 60, '…'), "$relevant/" . count($chunks), "$direct/" . count($chunks)];
        }
        ksort($cache);
        file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT) . "\n");

        $n = max(1, count($all));
        $summary = [
            'n' => count($all),
            'relevant_avg' => round(array_sum(array_column($all, 'relevant')) / $n, 2),
            'direct_avg' => round(array_sum(array_column($all, 'direct')) / $n, 2),
        ];
        $io->section('Pytania ogólne czytelników (ocena modelu)');
        $io->table(['id', 'pytanie', 'na temat', 'wprost'], $rows);
        $io->writeln(sprintf(' Średnio na temat: <info>%.2f</info> z 8, wprost odpowiada: <info>%.2f</info> z 8.', $summary['relevant_avg'], $summary['direct_avg']));
        return ['summary' => $summary, 'items' => $all];
    }

    private function compare(SymfonyStyle $io, ?array $old, array $new): void
    {
        if (!$old) {
            $io->warning('Nie da się odczytać pliku do porównania.');
            return;
        }
        $io->section(sprintf('Porównanie: %s → %s', $old['label'], $new['label']));
        $a = $old['summary']['wszystkie'];
        $b = $new['summary']['wszystkie'];
        $rows = [];
        foreach (['hit1' => '@1 %', 'hit3' => '@3 %', 'hit8' => '@8 %', 'hit20' => '@20 %', 'mrr8' => 'MRR@8', 'sem8' => 'semant.@8 %', 'fts8' => 'pełnotekst.@8 %'] as $k => $label) {
            $d = $b[$k] - $a[$k];
            $rows[] = [$label, $a[$k], $b[$k], ($d > 0 ? '+' : '') . round($d, 3)];
        }
        if (isset($old['topics']['summary'], $new['topics']['summary'])) {
            $rows[] = ['ogólne: na temat /8', $old['topics']['summary']['relevant_avg'], $new['topics']['summary']['relevant_avg'], round($new['topics']['summary']['relevant_avg'] - $old['topics']['summary']['relevant_avg'], 2)];
        }
        $io->table(['miara', $old['label'], $new['label'], 'różnica'], $rows);

        $prev = array_column($old['items'], 'rank', 'id');
        $gained = $lost = [];
        foreach ($new['items'] as $r) {
            if (!array_key_exists($r['id'], $prev)) {
                continue;
            }
            $was = $prev[$r['id']] !== null && $prev[$r['id']] <= self::K;
            $is = $r['rank'] !== null && $r['rank'] <= self::K;
            if ($is && !$was) {
                $gained[] = $r['id'];
            } elseif ($was && !$is) {
                $lost[] = $r['id'];
            }
        }
        $io->writeln(' Zyskane (teraz w ósemce): ' . ($gained ? implode(', ', $gained) : '—'));
        $io->writeln(' Stracone (wypadły z ósemki): ' . ($lost ? implode(', ', $lost) : '—'));
    }
}
