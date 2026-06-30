<?php

namespace App\Service;

use App\Entity\RagAnswer;
use App\Entity\RagQuery;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Historia rozmów asystenta per konto. Schemat „treść raz + wskaźnik”:
 * odpowiedź zapisywana jest RAZ w rag_answer (deduplikacja po hashu zakresu+epoki+
 * pytania, identycznym z kluczem cache), a per-userowy wiersz rag_query wskazuje na
 * nią `answer_id`. Czytanie historii nie wywołuje modelu i nie kosztuje kredytów.
 */
class RagHistory
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private RagCache $cache,
    ) {}

    /**
     * Utrwala treść odpowiedzi (raz, współdzielona) i podpina ją pod wiersz logu
     * danego użytkownika. Wołane po wygenerowaniu odpowiedzi LUB po trafieniu w cache.
     */
    public function attach(RagQuery $rq, string $question, ?int $volumeId, string $answer, array $citations): void
    {
        if (trim($answer) === '') {
            return;
        }
        $hash = $this->cache->keyFor($question, $volumeId);

        // Treść zapisywana raz; równoległe duplikaty ignorowane (first write wins).
        $this->db->executeStatement(
            'INSERT INTO rag_answer (content_hash, question, volume_id, answer, citations)
             VALUES (:h, :q, :v, :a, :c::jsonb)
             ON CONFLICT (content_hash) DO NOTHING',
            [
                'h' => $hash,
                'q' => $question,
                'v' => $volumeId,
                'a' => $answer,
                'c' => json_encode(array_values($citations), JSON_UNESCAPED_UNICODE),
            ]
        );
        $answerId = $this->db->fetchOne('SELECT id FROM rag_answer WHERE content_hash = :h', ['h' => $hash]);
        if ($answerId === false || $answerId === null) {
            return;
        }
        $rq->setAnswer($this->em->getReference(RagAnswer::class, (int) $answerId));
        $this->em->flush();
    }

    /**
     * Historia konta: przypięte na górze, potem najnowsze. Deduplikacja po treści
     * (to samo pytanie zadane wielokrotnie = jeden wpis; przypięty/najświeższy wygrywa).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 200): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT ON (q.answer_id)
                    q.id, q.question, q.volume_id, v.number AS volume_number,
                    q.created_at, q.pinned, a.answer, a.citations
             FROM rag_query q
             JOIN rag_answer a ON a.id = q.answer_id
             LEFT JOIN volumes v ON v.id = q.volume_id
             WHERE q.user_id = :uid
             ORDER BY q.answer_id, q.pinned DESC, q.created_at DESC",
            ['uid' => $userId]
        );

        foreach ($rows as &$r) {
            $r['pinned'] = (bool) $r['pinned'];
            $r['citations'] = is_string($r['citations']) ? (json_decode($r['citations'], true) ?: []) : ($r['citations'] ?: []);
            $r['scope_label'] = $r['volume_number'] !== null
                ? 'Tom ' . $this->roman((int) $r['volume_number'])
                : 'Wszystkie tomy';
        }
        unset($r);

        // Przypięte najpierw, potem malejąco po dacie.
        usort($rows, static function (array $a, array $b): int {
            if ($a['pinned'] !== $b['pinned']) {
                return $a['pinned'] ? -1 : 1;
            }
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return array_slice($rows, 0, $limit);
    }

    /** Liczba pozycji w historii (po deduplikacji). */
    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(DISTINCT q.answer_id) FROM rag_query q WHERE q.user_id = :uid AND q.answer_id IS NOT NULL',
            ['uid' => $userId]
        );
    }

    /** Przełącza przypięcie wpisu należącego do użytkownika. Zwraca nowy stan lub null. */
    public function togglePin(int $queryId, int $userId): ?bool
    {
        $rq = $this->ownedQuery($queryId, $userId);
        if (!$rq) {
            return null;
        }
        $rq->setPinned(!$rq->isPinned());
        $this->em->flush();
        return $rq->isPinned();
    }

    /**
     * Usuwa odpowiedź z historii użytkownika (odpina answer_id we wszystkich jego
     * wierszach wskazujących tę treść). Zachowuje wiersz logu dla rozliczeń (koszt/tokeny).
     */
    public function deleteForUser(int $queryId, int $userId): bool
    {
        $rq = $this->ownedQuery($queryId, $userId);
        if (!$rq || $rq->getAnswer() === null) {
            return false;
        }
        $answerId = $rq->getAnswer()->getId();
        $this->db->executeStatement(
            'UPDATE rag_query SET answer_id = NULL, pinned = false, pinned_at = NULL WHERE user_id = :uid AND answer_id = :aid',
            ['uid' => $userId, 'aid' => $answerId]
        );
        $this->em->clear(RagQuery::class);
        return true;
    }

    /** Czyści całą historię użytkownika (bez kasowania logów finansowych). */
    public function clearForUser(int $userId): int
    {
        return (int) $this->db->executeStatement(
            'UPDATE rag_query SET answer_id = NULL, pinned = false, pinned_at = NULL WHERE user_id = :uid AND answer_id IS NOT NULL',
            ['uid' => $userId]
        );
    }

    private function ownedQuery(int $queryId, int $userId): ?RagQuery
    {
        $rq = $this->em->createQuery(
            'SELECT q FROM ' . RagQuery::class . ' q WHERE q.id = :id'
        )->setParameter('id', $queryId)->getOneOrNullResult();

        return ($rq instanceof RagQuery && $rq->getUserId() === $userId) ? $rq : null;
    }

    private function roman(int $n): string
    {
        $map = [1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
                50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'];
        $out = '';
        foreach ($map as $val => $sym) {
            while ($n >= $val) { $out .= $sym; $n -= $val; }
        }
        return $out;
    }
}
