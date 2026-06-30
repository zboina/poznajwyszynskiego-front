<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Hybrid retrieval over document_chunks: semantic (bge-m3 cosine) + Polish
 * full-text, merged with Reciprocal Rank Fusion. Returns chunks enriched with
 * citation metadata (document title, volume number, printed-page range).
 */
class ChunkRetriever
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';
    private const MODEL = 'bge-m3';
    private const RRF_K = 60;
    private const CANDIDATES = 50; // per-arm candidate pool before fusion

    /** Ogranicza kandydatów do chunków z tomów opublikowanych (alias chunków: c). */
    private const PUBLISHED_FILTER = "AND c.volume_id IN (SELECT vp.id FROM volumes vp WHERE vp.status = 'opublikowany')";

    public function __construct(private Connection $db) {}

    /**
     * Volumes that currently have chunks (i.e. are answerable by the assistant),
     * ordered by volume number.
     *
     * @return array<int, array{number:int, title:string, chunks:int}>
     */
    public function availableVolumes(): array
    {
        $sql = "
            SELECT v.number, v.title, COUNT(c.id) AS chunks
            FROM document_chunks c
            JOIN volumes v ON v.id = c.volume_id
            WHERE v.status = 'opublikowany'
            GROUP BY v.number, v.title
            ORDER BY v.number
        ";
        $rows = $this->db->fetchAllAssociative($sql);
        return array_map(static fn($r) => [
            'number' => (int) $r['number'],
            'title' => (string) $r['title'],
            'chunks' => (int) $r['chunks'],
        ], $rows);
    }

    /**
     * @return array<int, array{
     *   id:int, document_id:int, content:string, page_start:?int, page_end:?int,
     *   chunk_index:int, title:string, slug:?string, volume_number:int, score:float
     * }>
     */
    public function retrieve(string $query, int $k = 8, ?int $volumeId = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $embedding = $this->embed($query);
        if ($embedding === null) {
            // Semantic arm unavailable → fall back to pure FTS.
            return $this->ftsOnly($query, $k, $volumeId);
        }

        // Tylko tomy opublikowane są odpytywalne — zembedowany tom w korekcie nie
        // może trafić do odpowiedzi/cytatów, dopóki nie zmieni statusu na 'opublikowany'.
        $volFilter = self::PUBLISHED_FILTER;
        if ($volumeId) {
            $volFilter .= ' AND c.volume_id = :vol';
        }

        $sql = "
            WITH fts AS (
                SELECT c.id, ROW_NUMBER() OVER (
                           ORDER BY ts_rank(c.search_vector, websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q)) DESC
                       ) AS rn
                FROM document_chunks c
                WHERE c.search_vector @@ (websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q))
                      {$volFilter}
                ORDER BY rn
                LIMIT :cand
            ),
            sem AS (
                SELECT c.id, ROW_NUMBER() OVER (ORDER BY c.embedding <=> :emb::vector) AS rn
                FROM document_chunks c
                WHERE c.embedding IS NOT NULL
                      {$volFilter}
                ORDER BY c.embedding <=> :emb::vector
                LIMIT :cand
            ),
            rrf AS (
                SELECT COALESCE(fts.id, sem.id) AS id,
                       COALESCE(1.0 / (:rrfk + fts.rn), 0) + COALESCE(1.0 / (:rrfk + sem.rn), 0) AS score
                FROM fts FULL OUTER JOIN sem ON fts.id = sem.id
            )
            SELECT c.id, c.document_id, c.content, c.page_start, c.page_end, c.chunk_index,
                   d.title, d.slug, v.number AS volume_number, rrf.score
            FROM rrf
            JOIN document_chunks c ON c.id = rrf.id
            JOIN documents d ON d.id = c.document_id
            JOIN volumes   v ON v.id = c.volume_id
            ORDER BY rrf.score DESC
            LIMIT :k
        ";

        $params = [
            'q' => $query,
            'emb' => '[' . implode(',', $embedding) . ']',
            'rrfk' => self::RRF_K,
            'cand' => self::CANDIDATES,
            'k' => $k,
        ];
        $types = ['rrfk' => ParameterType::INTEGER, 'cand' => ParameterType::INTEGER, 'k' => ParameterType::INTEGER];
        if ($volumeId) {
            $params['vol'] = $volumeId;
            $types['vol'] = ParameterType::INTEGER;
        }

        return $this->normalize($this->db->executeQuery($sql, $params, $types)->fetchAllAssociative());
    }

    private function ftsOnly(string $query, int $k, ?int $volumeId): array
    {
        $volFilter = self::PUBLISHED_FILTER;
        if ($volumeId) {
            $volFilter .= ' AND c.volume_id = :vol';
        }
        $sql = "
            SELECT c.id, c.document_id, c.content, c.page_start, c.page_end, c.chunk_index,
                   d.title, d.slug, v.number AS volume_number,
                   ts_rank(c.search_vector, websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q)) AS score
            FROM document_chunks c
            JOIN documents d ON d.id = c.document_id
            JOIN volumes   v ON v.id = c.volume_id
            WHERE c.search_vector @@ (websearch_to_tsquery('polish', :q) || websearch_to_tsquery('polish_unaccent', :q)) {$volFilter}
            ORDER BY score DESC
            LIMIT :k
        ";
        $params = ['q' => $query, 'k' => $k];
        $types = ['k' => ParameterType::INTEGER];
        if ($volumeId) {
            $params['vol'] = $volumeId;
            $types['vol'] = ParameterType::INTEGER;
        }
        return $this->normalize($this->db->executeQuery($sql, $params, $types)->fetchAllAssociative());
    }

    private function normalize(array $rows): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'document_id' => (int) $r['document_id'],
            'content' => (string) $r['content'],
            'page_start' => $r['page_start'] !== null ? (int) $r['page_start'] : null,
            'page_end' => $r['page_end'] !== null ? (int) $r['page_end'] : null,
            'chunk_index' => (int) $r['chunk_index'],
            'title' => (string) $r['title'],
            'slug' => $r['slug'] !== null ? (string) $r['slug'] : null,
            'volume_number' => (int) $r['volume_number'],
            'score' => (float) $r['score'],
        ], $rows);
    }

    /** @return float[]|null */
    public function embed(string $text): ?array
    {
        $ch = curl_init(self::OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => self::MODEL, 'prompt' => $text]),
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
}
