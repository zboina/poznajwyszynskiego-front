<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Splits documents into retrieval chunks, maps each chunk to the printed-page
 * range (from documents.page_breaks) and embeds it with bge-m3 via Ollama.
 *
 * Chunks are paragraph-aligned (paragraphs delimited by blank lines, matching
 * the page_breaks paragraph index). Oversized paragraphs are split by sentence.
 */
#[AsCommand(
    name: 'app:build-chunks',
    description: 'Chunk documents + embed with bge-m3 (Ollama) into document_chunks for RAG',
)]
class BuildChunksCommand extends Command
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';
    private const MODEL = 'bge-m3';
    private const TARGET_CHARS = 1600;   // ~400 tokens of Polish
    private const PARALLEL = 4;          // concurrent Ollama requests

    public function __construct(private Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('volume', null, InputOption::VALUE_REQUIRED, 'Volume number to (re)build')
            ->addOption('doc', null, InputOption::VALUE_REQUIRED, 'Single document id (overrides --volume)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $docId = $input->getOption('doc');
        $volumeNo = $input->getOption('volume');

        if ($docId) {
            $docs = $this->db->executeQuery(
                'SELECT d.id, d.content, d.pdf_page_start, d.page_breaks, d.volume_id
                 FROM documents d WHERE d.id = :id',
                ['id' => (int) $docId]
            )->fetchAllAssociative();
        } elseif ($volumeNo) {
            $docs = $this->db->executeQuery(
                'SELECT d.id, d.content, d.pdf_page_start, d.page_breaks, d.volume_id
                 FROM documents d JOIN volumes v ON v.id = d.volume_id
                 WHERE v.number = :n ORDER BY d.number_in_volume',
                ['n' => (int) $volumeNo]
            )->fetchAllAssociative();
        } else {
            $io->error('Podaj --volume=N albo --doc=ID');
            return Command::INVALID;
        }

        if (!$docs) {
            $io->warning('Brak dokumentów.');
            return Command::SUCCESS;
        }

        // Rebuild: drop existing chunks for the affected documents.
        $ids = array_map(fn($d) => (int) $d['id'], $docs);
        $this->db->executeStatement(
            'DELETE FROM document_chunks WHERE document_id IN (' . implode(',', $ids) . ')'
        );

        $io->title(sprintf('Chunking + embedding (bge-m3): %d dok.', count($docs)));

        // 1) Build all chunk units first (cheap), then embed in parallel batches.
        $pending = []; // [document_id, volume_id, chunk_index, content, page_start, page_end, char_count]
        foreach ($docs as $d) {
            $units = $this->chunkDocument(
                (string) $d['content'],
                $d['pdf_page_start'] !== null ? (int) $d['pdf_page_start'] : null,
                $this->decodeBreaks($d['page_breaks'])
            );
            foreach ($units as $idx => $u) {
                $pending[] = [
                    'document_id' => (int) $d['id'],
                    'volume_id' => (int) $d['volume_id'],
                    'chunk_index' => $idx,
                    'content' => $u['text'],
                    'page_start' => $u['page_start'],
                    'page_end' => $u['page_end'],
                    'char_count' => strlen($u['text']),
                ];
            }
        }

        $total = count($pending);
        $io->info("Chunków do osadzenia: {$total} (równolegle: " . self::PARALLEL . ')');

        $bar = new ProgressBar($output, $total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $bar->start();

        $insert = $this->db->prepare(
            'INSERT INTO document_chunks
                (document_id, volume_id, chunk_index, content, page_start, page_end, char_count, embedding)
             VALUES (:document_id, :volume_id, :chunk_index, :content, :page_start, :page_end, :char_count, :embedding::vector)'
        );

        $errors = 0;
        foreach (array_chunk($pending, self::PARALLEL) as $batch) {
            $embeddings = $this->embedBatch(array_map(fn($r) => $r['content'], $batch));
            foreach ($batch as $i => $row) {
                $emb = $embeddings[$i] ?? null;
                if ($emb === null) {
                    $errors++;
                    $bar->advance();
                    continue;
                }
                $insert->bindValue('document_id', $row['document_id']);
                $insert->bindValue('volume_id', $row['volume_id']);
                $insert->bindValue('chunk_index', $row['chunk_index']);
                $insert->bindValue('content', $row['content']);
                $insert->bindValue('page_start', $row['page_start']);
                $insert->bindValue('page_end', $row['page_end']);
                $insert->bindValue('char_count', $row['char_count']);
                $insert->bindValue('embedding', '[' . implode(',', $emb) . ']');
                $insert->executeStatement();
                $bar->advance();
            }
        }
        $bar->finish();
        $output->writeln('');

        $io->success(sprintf('Zapisano %d/%d chunków%s', $total - $errors, $total, $errors ? " (błędy: {$errors})" : ''));

        // Korpus się zmienił → unieważnij cache odpowiedzi asystenta (jeśli tabela istnieje).
        try {
            $cleared = (int) $this->db->executeStatement('DELETE FROM rag_cache');
            if ($cleared > 0) {
                $io->writeln(sprintf('Wyczyszczono cache asystenta: %d wpisów.', $cleared));
            }
        } catch (\Throwable $e) {
            // tabela rag_cache może jeszcze nie istnieć — pomijamy
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return array<int, array{paragraph:int, page:int, char:int}> */
    private function decodeBreaks(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return [];
        }
        $breaks = [];
        foreach ($decoded as $b) {
            if (!isset($b['paragraph'], $b['page'])) {
                continue;
            }
            $breaks[] = [
                'paragraph' => (int) $b['paragraph'],
                'page' => (int) $b['page'],
                'char' => (int) ($b['char_in_para'] ?? 0),
            ];
        }
        usort($breaks, fn($a, $b) => [$a['paragraph'], $a['char']] <=> [$b['paragraph'], $b['char']]);
        return $breaks;
    }

    /**
     * Printed page at (paragraph, byteOffset): starts at $base, advances to the
     * page of the latest break that lies at or before the position.
     */
    private function pageAt(?int $base, array $breaks, int $para, int $byteOff): ?int
    {
        $page = $base;
        foreach ($breaks as $b) {
            if ($b['paragraph'] < $para || ($b['paragraph'] === $para && $b['char'] <= $byteOff)) {
                $page = $b['page'];
            } else {
                break;
            }
        }
        return $page;
    }

    /**
     * @return array<int, array{text:string, page_start:?int, page_end:?int}>
     */
    private function chunkDocument(string $content, ?int $pdfStart, array $breaks): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $content) ?: [];

        // Build atomic units: one per paragraph, or several (sentence groups) for long ones.
        // Each unit carries its paragraph index and byte span within that paragraph.
        $units = [];
        foreach ($paragraphs as $pIdx => $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (strlen($para) <= self::TARGET_CHARS) {
                $units[] = ['para' => $pIdx, 'start' => 0, 'end' => strlen($para), 'text' => $para];
                continue;
            }
            // Split oversized paragraph by sentence boundaries, regrouped to TARGET_CHARS.
            $sentences = preg_split('/(?<=[.!?…])\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) ?: [$para];
            $buf = '';
            $bufStart = 0;
            $cursor = 0;
            foreach ($sentences as $s) {
                $sLen = strlen($s);
                if ($buf !== '' && strlen($buf) + 1 + $sLen > self::TARGET_CHARS) {
                    $units[] = ['para' => $pIdx, 'start' => $bufStart, 'end' => $cursor, 'text' => $buf];
                    $buf = '';
                    $bufStart = $cursor + 1;
                }
                $buf = $buf === '' ? $s : $buf . ' ' . $s;
                $cursor += ($cursor === 0 ? 0 : 1) + $sLen;
            }
            if ($buf !== '') {
                $units[] = ['para' => $pIdx, 'start' => $bufStart, 'end' => strlen($para), 'text' => $buf];
            }
        }

        // Group consecutive units into chunks up to TARGET_CHARS.
        $chunks = [];
        $cur = [];
        $curLen = 0;
        $flush = function () use (&$chunks, &$cur, &$curLen, $pdfStart, $breaks) {
            if (!$cur) {
                return;
            }
            $first = $cur[0];
            $last = $cur[count($cur) - 1];
            $chunks[] = [
                'text' => implode("\n\n", array_map(fn($u) => $u['text'], $cur)),
                'page_start' => $this->pageAt($pdfStart, $breaks, $first['para'], $first['start']),
                'page_end' => $this->pageAt($pdfStart, $breaks, $last['para'], max(0, $last['end'] - 1)),
            ];
            $cur = [];
            $curLen = 0;
        };
        foreach ($units as $u) {
            $uLen = strlen($u['text']);
            if ($cur && $curLen + $uLen > self::TARGET_CHARS) {
                $flush();
            }
            $cur[] = $u;
            $curLen += $uLen;
        }
        $flush();

        return $chunks;
    }

    /**
     * Embed a batch of texts in parallel via Ollama. Returns array index => float[] | null.
     */
    private function embedBatch(array $texts): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($texts as $i => $text) {
            $ch = curl_init(self::OLLAMA_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['model' => self::MODEL, 'prompt' => $text]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $i => $ch) {
            $resp = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $results[$i] = ($code === 200 && $resp) ? (json_decode($resp, true)['embedding'] ?? null) : null;
        }
        curl_multi_close($mh);
        return $results;
    }
}
