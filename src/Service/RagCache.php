<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Exact-match cache of assistant answers. An identical question (per volume
 * scope) is served from the database instead of re-calling the paid model.
 * Safe by design: only verbatim-normalised matches hit, so we never serve a
 * different question's answer.
 */
class RagCache
{
    /** Memoizacja sygnatury korpusu w obrębie jednego żądania. */
    private ?string $epoch = null;

    public function __construct(private Connection $db) {}

    /** Lowercase, collapse whitespace, drop trailing punctuation. */
    public function normalize(string $q): string
    {
        $q = mb_strtolower(trim($q), 'UTF-8');
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        return rtrim($q, " \t\n\r?!.…");
    }

    /**
     * Sygnatura „epoki korpusu”: md5 z posortowanych id tomów OPUBLIKOWANYCH,
     * które mają chunki (czyli realnie odpowiadalnych przez asystenta). Zmienia
     * się przy publikacji / cofnięciu publikacji tomu, więc odpowiedzi o zakresie
     * „wszystkie tomy” trafiają automatycznie do nowej przestrzeni cache — bez
     * ręcznego czyszczenia. Zmiana treści już opublikowanego tomu (re-chunking)
     * idzie osobną ścieżką: BuildChunksCommand czyści cały cache.
     */
    private function corpusEpoch(): string
    {
        if ($this->epoch !== null) {
            return $this->epoch;
        }
        $sig = $this->db->fetchOne(
            "SELECT coalesce(md5(string_agg(id::text, ',' ORDER BY id)), '0')
             FROM (
                 SELECT DISTINCT v.id
                 FROM volumes v
                 JOIN document_chunks c ON c.volume_id = v.id
                 WHERE v.status = 'opublikowany'
             ) t"
        );
        return $this->epoch = is_string($sig) ? $sig : '0';
    }

    /**
     * Publiczny klucz treści dla pary (pytanie, zakres) — ten sam, którym posługuje
     * się cache. Używany przez historię (RagHistory) do deduplikacji odpowiedzi:
     * identyczne pytanie w tej samej epoce korpusu → ten sam wiersz rag_answer.
     */
    public function keyFor(string $question, ?int $volumeId): string
    {
        return $this->key($this->normalize($question), $volumeId);
    }

    private function key(string $norm, ?int $volumeId): string
    {
        // Zakres „wszystkie tomy” zależy od zestawu opublikowanych tomów → wplatamy
        // epokę korpusu. Zakres „konkretny tom” jest od reszty niezależny → klucz
        // stabilny (zgodny ze starym schematem, więc cache per-tom przeżywa publikacje).
        $scope = $volumeId !== null
            ? (string) $volumeId
            : '0:' . $this->corpusEpoch();
        return hash('sha256', $scope . '|' . $norm);
    }

    /**
     * @return array{answer:string, citations:array}|null
     */
    public function get(string $question, ?int $volumeId): ?array
    {
        $hash = $this->key($this->normalize($question), $volumeId);
        $row = $this->db->fetchAssociative(
            'SELECT answer, citations FROM rag_cache WHERE q_hash = :h',
            ['h' => $hash]
        );
        if (!$row) {
            return null;
        }
        $this->db->executeStatement(
            'UPDATE rag_cache SET hits = hits + 1, last_hit_at = now() WHERE q_hash = :h',
            ['h' => $hash]
        );
        $citations = is_string($row['citations']) ? json_decode($row['citations'], true) : $row['citations'];
        return [
            'answer' => (string) $row['answer'],
            'citations' => is_array($citations) ? $citations : [],
        ];
    }

    /** Store an answer (first write wins; concurrent duplicates are ignored). */
    public function put(string $question, ?int $volumeId, string $answer, array $citations): void
    {
        $norm = $this->normalize($question);
        if ($norm === '' || trim($answer) === '') {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO rag_cache (q_hash, q_norm, volume_id, question, answer, citations)
             VALUES (:h, :n, :v, :q, :a, :c::jsonb)
             ON CONFLICT (q_hash) DO NOTHING',
            [
                'h' => $this->key($norm, $volumeId),
                'n' => $norm,
                'v' => $volumeId,
                'q' => $question,
                'a' => $answer,
                'c' => json_encode(array_values($citations), JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    /** Invalidate cache after a re-index. Volume-scoped clears also drop the
     *  all-volumes answers, which depend on every volume. */
    public function clear(?int $volumeId = null): int
    {
        if ($volumeId === null) {
            return (int) $this->db->executeStatement('DELETE FROM rag_cache');
        }
        return (int) $this->db->executeStatement(
            'DELETE FROM rag_cache WHERE volume_id = :v OR volume_id IS NULL',
            ['v' => $volumeId]
        );
    }
}
