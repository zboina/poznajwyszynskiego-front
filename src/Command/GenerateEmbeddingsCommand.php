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

#[AsCommand(
    name: 'app:generate-embeddings',
    description: 'Generate vector embeddings for all documents using Ollama nomic-embed-text',
)]
class GenerateEmbeddingsCommand extends Command
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';
    private const MODEL = 'all-minilm';
    private const MAX_CHARS = 2000;
    private const PARALLEL = 4; // concurrent Ollama requests

    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Regenerate all embeddings (even existing ones)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('Generowanie embeddingów dokumentów');

        $whereClause = $force ? '' : 'WHERE embedding IS NULL';
        $total = (int) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM documents {$whereClause}"
        )->fetchOne();

        if ($total === 0) {
            $io->success('Wszystkie dokumenty mają już embeddingi. Użyj --force aby wygenerować ponownie.');
            return Command::SUCCESS;
        }

        $io->info("Dokumentów do przetworzenia: {$total} (równolegle: " . self::PARALLEL . ")");

        $rows = $this->connection->executeQuery(
            "SELECT id, title, subtitle, content FROM documents {$whereClause} ORDER BY id"
        )->fetchAllAssociative();

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $processed = 0;
        $errors = 0;

        // Process in batches using multi-curl
        $batches = array_chunk($rows, self::PARALLEL);

        foreach ($batches as $batch) {
            $results = $this->getEmbeddingsBatch($batch);

            foreach ($batch as $i => $row) {
                $embedding = $results[$i] ?? null;

                if ($embedding === null) {
                    $errors++;
                    $progressBar->advance();
                    continue;
                }

                $vectorStr = '[' . implode(',', $embedding) . ']';
                $this->connection->executeStatement(
                    'UPDATE documents SET embedding = :embedding WHERE id = :id',
                    ['embedding' => $vectorStr, 'id' => $row['id']]
                );

                $processed++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $output->writeln('');

        $io->success("Wygenerowano embeddingi: {$processed}/{$total}" . ($errors ? " (błędy: {$errors})" : ''));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process a batch of documents in parallel using multi-curl.
     */
    private function getEmbeddingsBatch(array $rows): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($rows as $i => $row) {
            $text = $this->prepareText($row);
            $payload = json_encode([
                'model' => self::MODEL,
                'prompt' => $text,
            ]);

            $ch = curl_init(self::OLLAMA_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        // Execute all requests
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $results = [];
        foreach ($handles as $i => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $results[$i] = $data['embedding'] ?? null;
            } else {
                $results[$i] = null;
            }
        }

        curl_multi_close($mh);

        return $results;
    }

    private function prepareText(array $row): string
    {
        $parts = [];

        if (!empty($row['title'])) {
            $parts[] = $row['title'];
        }
        if (!empty($row['subtitle'])) {
            $parts[] = $row['subtitle'];
        }
        if (!empty($row['content'])) {
            $content = strip_tags($row['content']);
            $content = preg_replace('/\s+/', ' ', $content);
            $parts[] = trim($content);
        }

        $text = implode('. ', $parts);

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS);
        }

        return $text;
    }
}
