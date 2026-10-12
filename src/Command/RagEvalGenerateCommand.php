<?php

namespace App\Command;

use App\Service\RagEvalTools;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Buduje zestaw pytań wzorcowych (rag-eval/golden.json): losuje fragmenty z
 * opublikowanych tomów i prosi model o pytanie, na które odpowiada właśnie ten
 * fragment, oraz o dosłowne zdanie-kotwicę. Baza jest tylko czytana.
 *
 * Wygenerowane pytania trzeba przejrzeć (pole "checked") — model bywa zbyt
 * dosłowny albo pyta o rzecz obecną w wielu tekstach.
 */
#[AsCommand(name: 'app:rag-eval:generate', description: 'Wygeneruj pytania wzorcowe do testu wyszukiwania asystenta')]
class RagEvalGenerateCommand extends Command
{
    private const SYSTEM = <<<'TXT'
        Przygotowujesz zestaw testowy dla wyszukiwarki w Dziełach Zebranych kardynała Stefana Wyszyńskiego.
        Dostajesz jeden fragment tekstu. Napisz pytanie, jakie mógłby wpisać czytelnik, i na które odpowiedź jest właśnie w tym fragmencie.

        Zasady:
        - Pytanie ma dotyczyć konkretnej, charakterystycznej myśli, obrazu, wydarzenia lub postaci z fragmentu — takiej, która raczej nie powtarza się w setkach innych kazań. Unikaj ogólników typu „Co Prymas mówił o miłości?”.
        - Pisz własnymi słowami, jak zwykły czytelnik. NIE przepisuj fraz z fragmentu; użyj synonimów i innego szyku. Nie podawaj tytułu tekstu, numeru tomu ani daty, chyba że czytelnik naturalnie by o nią zapytał.
        - "anchor": skopiuj DOSŁOWNIE z fragmentu jedno lub dwa kolejne zdania (60–300 znaków), które najlepiej odpowiadają na pytanie. Znak w znak, bez skrótów i wielokropków.
        - Jeśli fragment nie nadaje się na pytanie (spis, przypisy, same nazwiska, urwany wstęp, modlitwa bez treści do zapytania), zwróć {"skip": true}.

        Odpowiedz wyłącznie JSON-em: {"skip": false, "question": "...", "anchor": "..."}
        TXT;

    private const STYLES = [
        'pytanie' => 'Forma: pełne pytanie, np. „Jak Prymas tłumaczył…?”, „Dlaczego kardynał uważał, że…?”.',
        'haslo' => 'Forma: krótkie hasło (2–5 słów), jakie ludzie wpisują w wyszukiwarkę, bez znaku zapytania, np. „Prymas o pracy rolnika”.',
    ];

    public function __construct(private Connection $db, private RagEvalTools $tools)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('per-volume', null, InputOption::VALUE_REQUIRED, 'Ile pytań z każdego tomu', '9')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Ziarno losowania (ten sam seed = te same fragmenty)', 'wyszynski-2026')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model OpenRouter piszący pytania', 'google/gemini-2.5-flash')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Plik wynikowy', 'rag-eval/golden.json')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Nadpisz istniejący plik');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $out = $input->getOption('out');
        if (file_exists($out) && !$input->getOption('force')) {
            $io->error("$out już istnieje (może zawierać ręczne poprawki). Użyj --force albo innego --out.");
            return Command::FAILURE;
        }
        $perVolume = (int) $input->getOption('per-volume');
        $model = $input->getOption('model');

        // Po jednym fragmencie z dokumentu, deterministycznie wg seeda; tylko pełne
        // akapity (nie przypisy i urwane końcówki), tylko tomy opublikowane.
        $candidates = $this->db->fetchAllAssociative(
            "SELECT * FROM (
                 SELECT DISTINCT ON (c.document_id) c.id, c.document_id, c.content, v.number AS volume, d.title,
                        md5(c.id::text || :seed) AS h
                 FROM document_chunks c
                 JOIN documents d ON d.id = c.document_id
                 JOIN volumes v ON v.id = c.volume_id
                 WHERE v.status = 'opublikowany' AND c.char_count BETWEEN 800 AND 1800
                 ORDER BY c.document_id, md5(c.id::text || :seed)
             ) x ORDER BY volume, h",
            ['seed' => $input->getOption('seed')]
        );
        $byVolume = [];
        foreach ($candidates as $c) {
            $byVolume[$c['volume']][] = $c;
        }

        $items = [];
        $n = 0;
        foreach ($byVolume as $volume => $chunks) {
            $made = 0;
            foreach ($chunks as $c) {
                if ($made >= $perVolume) {
                    break;
                }
                $style = $n % 3 === 2 ? 'haslo' : 'pytanie'; // ok. 1/3 krótkich haseł
                try {
                    $r = $this->tools->llmJson($model, self::SYSTEM . "\n" . self::STYLES[$style],
                        "Tytuł tekstu: {$c['title']}\n\nFragment:\n{$c['content']}");
                } catch (\RuntimeException $e) {
                    $io->error($e->getMessage());
                    break 2;
                }
                if (!$r || !empty($r['skip']) || empty($r['question']) || empty($r['anchor'])) {
                    $io->writeln("<comment>pominięty fragment {$c['id']} (tom {$volume})</comment>");
                    continue;
                }
                // Model musi zacytować wiernie — inaczej kotwica niczego nie znajdzie.
                $cov = RagEvalTools::coverage($r['anchor'], $c['content']);
                $len = mb_strlen($r['anchor']);
                if ($cov < 0.9 || $len < 40 || $len > 400) {
                    $io->writeln(sprintf('<comment>odrzucona kotwica (zgodność %.2f, %d zn.) fragment %d</comment>', $cov, $len, $c['id']));
                    continue;
                }
                $n++;
                $made++;
                $items[] = [
                    'id' => sprintf('g%03d', $n),
                    'type' => $style,
                    'question' => trim($r['question']),
                    'anchor' => trim($r['anchor']),
                    'document_id' => (int) $c['document_id'],
                    'volume' => (int) $volume,
                    'title' => $c['title'],
                    'checked' => false,
                ];
                $io->writeln(sprintf('[%s] tom %d: %s', $items[array_key_last($items)]['id'], $volume, $r['question']));
            }
        }

        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, json_encode([
            'description' => 'Pytania wzorcowe do testu wyszukiwania asystenta AI. Trafienie = fragment zawierający „anchor” (dosłowne zdanie z tekstu) w czołówce wyników. Pola "checked" ustawia człowiek po przejrzeniu.',
            'generated' => date('c'),
            'model' => $model,
            'seed' => $input->getOption('seed'),
            'items' => $items,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        $io->success(sprintf('%d pytań zapisano w %s. Koszt: %.4f USD.', count($items), $out, $this->tools->spentUsd));
        return Command::SUCCESS;
    }
}
