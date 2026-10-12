<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hybrid retrieval over document_chunks: semantic (bge-m3 cosine) + Polish
 * full-text, merged with Reciprocal Rank Fusion. Returns chunks enriched with
 * citation metadata (document title, volume number, printed-page range).
 *
 * Gdy ustawiony jest RERANKER_URL, czołówka wyników hybrydy trafia do polskiego
 * rerankera (reranker/, cross-encoder czytający pytanie razem z fragmentem),
 * który ustala ostateczną kolejność. Usługa niedostępna = kolejność z RRF.
 */
class ChunkRetriever
{
    private const OLLAMA_URL = 'http://localhost:11434/api/embeddings';
    private const MODEL = 'bge-m3';
    private const RRF_K = 60;
    private const CANDIDATES = 50; // per-arm candidate pool before fusion

    /** Ogranicza kandydatów do chunków z tomów opublikowanych (alias chunków: c). */
    private const PUBLISHED_FILTER = "AND c.volume_id IN (SELECT vp.id FROM volumes vp WHERE vp.status = 'opublikowany')";

    /**
     * Słowa pomijane w wyszukiwaniu pełnotekstowym: słowa pytające i łączniki
     * (słownik polish_ispell ich nie odrzuca) oraz słowa obecne w całym korpusie
     * („Prymas”, „mówił”) — nic nie mówią o tym, którego fragmentu szukamy.
     */
    private const FTS_STOPWORDS = [
        'co', 'czy', 'jak', 'jaki', 'jaka', 'jakie', 'jakich', 'jakim', 'jakimi', 'jakiej', 'jakiego', 'jakiemu',
        'dlaczego', 'czemu', 'kiedy', 'gdzie', 'skąd', 'dokąd', 'kto', 'kogo', 'komu', 'kim', 'czym', 'czego', 'ile',
        'który', 'która', 'które', 'którzy', 'którego', 'której', 'którym', 'których', 'ktory', 'ktora', 'ktore',
        'ten', 'ta', 'to', 'te', 'tego', 'tej', 'tym', 'tych', 'tę', 'tak', 'też', 'także', 'oraz', 'ale', 'albo', 'lub',
        'się', 'nie', 'dla', 'przez', 'przy', 'pod', 'nad', 'bez', 'aby', 'żeby', 'jego', 'jej', 'ich', 'jest', 'są',
        'był', 'była', 'było', 'były', 'byli', 'może', 'można', 'swój', 'swoje', 'swoich', 'swoim', 'według', 'zdaniem',
        'sposób', 'temat', 'tematu', 'kontekście', 'czyli', 'jako', 'jeśli', 'gdy', 'tylko', 'bardzo', 'coś', 'cos',
        'prymas', 'prymasa', 'prymasowi', 'prymasem', 'prymasie', 'kardynał', 'kardynała', 'kardynal', 'kardynala',
        'wyszyński', 'wyszyńskiego', 'wyszyńskim', 'wyszynski', 'wyszynskiego', 'stefan', 'stefana', 'ksiądz', 'księdza',
        'mówił', 'mówi', 'mowil', 'mówiło', 'powiedział', 'pisał', 'pisze', 'pisal', 'napisał', 'uważał', 'sądził',
        'rozumiał', 'widział', 'tłumaczył', 'wyjaśniał', 'opisywał', 'nauczał', 'nauczanie', 'nauczania', 'myślał',
    ];

    /** Ile czołowych fragmentów hybrydy ocenia reranker (koszt ~0,15 s na fragment). */
    private int $rerankCandidates;

    public function __construct(
        private Connection $db,
        private LoggerInterface $logger,
        #[Autowire(env: 'default::RERANKER_URL')] private ?string $rerankerUrl = null,
        #[Autowire(env: 'default::int:RERANKER_CANDIDATES')] ?int $rerankCandidates = null,
    ) {
        $this->rerankCandidates = $rerankCandidates ?? 20;
    }

    /** Do testów (app:rag-eval): 0 wyłącza reranker. */
    public function setRerankCandidates(int $n): void
    {
        $this->rerankCandidates = $n;
    }

    private function rerankEnabled(): bool
    {
        return $this->rerankerUrl !== null && $this->rerankerUrl !== '' && $this->rerankCandidates > 0;
    }

    /**
     * Pytanie → zapytanie pełnotekstowe „którekolwiek z istotnych słów”.
     * Wcześniej całe pytanie szło do websearch_to_tsquery, które wymaga WSZYSTKICH
     * słów (łącznie z „jak”, „Prymas”) — pasowało do ok. 1% pytań. Kolejność wyników
     * ustala ts_rank: wyżej fragmenty z większą liczbą słów pytania.
     */
    public function ftsQuery(string $query): string
    {
        static $stop = null;
        $stop ??= array_flip(self::FTS_STOPWORDS);
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $m);
        $words = [];
        foreach ($m[0] as $w) {
            if ((mb_strlen($w) > 2 || ctype_digit($w)) && !isset($stop[$w]) && $w !== 'or') {
                $words[$w] = true;
            }
        }
        return $words ? implode(' or ', array_keys($words)) : $query;
    }

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
                           ORDER BY ts_rank(c.search_vector, websearch_to_tsquery('polish', :fq) || websearch_to_tsquery('polish_unaccent', :fq)) DESC
                       ) AS rn
                FROM document_chunks c
                WHERE c.search_vector @@ (websearch_to_tsquery('polish', :fq) || websearch_to_tsquery('polish_unaccent', :fq))
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
            LIMIT :pool
        ";

        $params = [
            'fq' => $this->ftsQuery($query),
            'emb' => '[' . implode(',', $embedding) . ']',
            'rrfk' => self::RRF_K,
            'cand' => self::CANDIDATES,
            'pool' => $this->rerankEnabled() ? max($k, $this->rerankCandidates) : $k,
        ];
        $types = ['rrfk' => ParameterType::INTEGER, 'cand' => ParameterType::INTEGER, 'pool' => ParameterType::INTEGER];
        if ($volumeId) {
            $params['vol'] = $volumeId;
            $types['vol'] = ParameterType::INTEGER;
        }

        $chunks = $this->normalize($this->db->executeQuery($sql, $params, $types)->fetchAllAssociative());
        return array_slice($this->rerank($query, $chunks), 0, $k);
    }

    /**
     * Nowa kolejność czołówki wg rerankera; każdy oceniony fragment dostaje
     * 'rerank_score' (logit: >0 zwykle trafny, <-2 zwykle obok tematu).
     * Fragmenty spoza czołówki zostają za nią w kolejności RRF.
     */
    private function rerank(string $query, array $chunks): array
    {
        if (!$this->rerankEnabled() || count($chunks) < 2) {
            return $chunks;
        }
        $head = array_slice($chunks, 0, $this->rerankCandidates);
        $ch = curl_init(rtrim($this->rerankerUrl, '/') . '/rerank');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'documents' => array_column($head, 'content')]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $scores = $code === 200 ? (json_decode((string) $resp, true)['scores'] ?? null) : null;
        if (!is_array($scores) || count($scores) !== count($head)) {
            $this->logger->warning('Reranker niedostępny, kolejność z RRF: ' . ($err !== '' ? $err : 'HTTP ' . $code));
            return $chunks;
        }
        foreach ($head as $i => &$c) {
            $c['rerank_score'] = (float) $scores[$i];
        }
        unset($c);
        usort($head, fn($a, $b) => $b['rerank_score'] <=> $a['rerank_score']);
        return array_merge($head, array_slice($chunks, $this->rerankCandidates));
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
                   ts_rank(c.search_vector, websearch_to_tsquery('polish', :fq) || websearch_to_tsquery('polish_unaccent', :fq)) AS score
            FROM document_chunks c
            JOIN documents d ON d.id = c.document_id
            JOIN volumes   v ON v.id = c.volume_id
            WHERE c.search_vector @@ (websearch_to_tsquery('polish', :fq) || websearch_to_tsquery('polish_unaccent', :fq)) {$volFilter}
            ORDER BY score DESC
            LIMIT :k
        ";
        $params = ['fq' => $this->ftsQuery($query), 'k' => $k];
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
