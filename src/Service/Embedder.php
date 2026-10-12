<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Embeddingi fragmentów i pytań dla wyszukiwania semantycznego asystenta.
 *
 * Dwa modele, każdy we własnej kolumnie document_chunks:
 *  - 'bge-m3'   → embedding    (Ollama, 1024 wym.) — dotychczasowy,
 *  - 'poldense' → embedding_pd (OPI-PIB/PolDense-150M, kontener pw-embed, 768 wym.).
 * Którym szuka asystent, ustala env EMBEDDING_MODEL (domyślnie bge-m3), więc
 * powrót do poprzedniego modelu to zmiana jednej linijki.
 */
class Embedder
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';

    public const COLUMNS = ['bge-m3' => 'embedding', 'poldense' => 'embedding_pd'];

    private string $model;

    public function __construct(
        private Connection $db,
        #[Autowire(env: 'default::EMBEDDING_MODEL')] ?string $model = null,
        #[Autowire(env: 'default::POLDENSE_URL')] private ?string $poldenseUrl = null,
    ) {
        $this->model = isset(self::COLUMNS[$model ?? '']) ? $model : 'bge-m3';
        $this->poldenseUrl = $poldenseUrl ?: 'http://127.0.0.1:8004';
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Kolumna z wektorami modelu, którym szuka asystent. */
    public function column(): string
    {
        return self::COLUMNS[$this->model];
    }

    /** @return float[]|null */
    public function embedQuery(string $text): ?array
    {
        if ($this->model === 'poldense') {
            return $this->poldense([$text], 'query', 10)[0] ?? null;
        }
        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => 'bge-m3', 'prompt' => $text]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) {
            return null;
        }
        $emb = json_decode($resp, true)['embedding'] ?? null;
        return is_array($emb) ? $emb : null;
    }

    /**
     * Uzupełnia embedding_pd we fragmentach, które go nie mają (wznawialne).
     * @param int[]|null $documentIds null = wszystkie fragmenty
     * @param callable(int $done, int $total):void|null $progress
     * @return int liczba uzupełnionych fragmentów
     */
    public function fillPoldense(?array $documentIds = null, ?callable $progress = null, int $batch = 32): int
    {
        $where = 'embedding_pd IS NULL' . ($documentIds ? ' AND document_id IN (' . implode(',', array_map('intval', $documentIds)) . ')' : '');
        $total = (int) $this->db->fetchOne("SELECT count(*) FROM document_chunks WHERE $where");
        $done = 0;
        while ($rows = $this->db->fetchAllAssociative("SELECT id, content FROM document_chunks WHERE $where ORDER BY id LIMIT $batch")) {
            $vectors = $this->poldense(array_column($rows, 'content'), 'document', 300);
            if (count($vectors) !== count($rows)) {
                throw new \RuntimeException('Usługa PolDense (' . $this->poldenseUrl . ') nie zwróciła wektorów.');
            }
            foreach ($rows as $i => $r) {
                $this->db->executeStatement(
                    'UPDATE document_chunks SET embedding_pd = :v::vector WHERE id = :id',
                    ['v' => '[' . implode(',', $vectors[$i]) . ']', 'id' => $r['id']]
                );
            }
            $done += count($rows);
            if ($progress) {
                $progress($done, $total);
            }
        }
        return $done;
    }

    /** @return array<int,float[]> */
    private function poldense(array $texts, string $kind, int $timeout): array
    {
        $ch = curl_init(rtrim($this->poldenseUrl, '/') . '/embed');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['texts' => array_values($texts), 'kind' => $kind]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) {
            return [];
        }
        $emb = json_decode($resp, true)['embeddings'] ?? null;
        return is_array($emb) ? $emb : [];
    }
}
