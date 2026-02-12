<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;

class DocumentRepository extends ServiceEntityRepository
{
    public const VOLUME_STATUS_PUBLISHED = 'opublikowany';

    private Security $security;

    public function __construct(ManagerRegistry $registry, Security $security)
    {
        parent::__construct($registry, Document::class);
        $this->security = $security;
    }

    /**
     * Returns SQL filter for published volumes, or empty string for admins.
     * Use with alias "d" for the documents table.
     */
    public function publishedFilter(): string
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return '1=1';
        }

        return "EXISTS (SELECT 1 FROM volumes v WHERE v.id = d.volume_id AND v.status = 'opublikowany')";
    }

    /**
     * Returns JOIN clause for published volumes, or no-op for admins.
     * Use with alias "v" for volumes and "d" for documents.
     */
    public function publishedJoin(): string
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return 'LEFT JOIN volumes v ON v.id = d.volume_id';
        }

        return "JOIN volumes v ON v.id = d.volume_id AND v.status = 'opublikowany'";
    }

    public function search(
        ?string $query = null,
        ?int $volumeId = null,
        ?string $documentType = null,
        ?int $tagId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $page = 1,
        int $limit = 10,
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        $pf = $this->publishedFilter();
        $where = [$pf];
        $params = [];

        if ($query && trim($query) !== '') {
            $where[] = "d.search_vector @@ websearch_to_tsquery('polish', :query)";
            $params['query'] = trim($query);
        }

        if ($volumeId) {
            $where[] = 'd.volume_id = :volume_id';
            $params['volume_id'] = $volumeId;
        }

        if ($documentType) {
            $where[] = 'd.document_type = :document_type';
            $params['document_type'] = $documentType;
        }

        if ($tagId) {
            $where[] = 'EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = :tag_id)';
            $params['tag_id'] = $tagId;
        }

        if ($dateFrom) {
            $where[] = 'd.event_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where[] = 'd.event_date <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) FROM documents d {$whereClause}";
        $total = (int) $conn->executeQuery($countSql, $params)->fetchOne();

        // Fetch results with headline if query provided
        $offset = ($page - 1) * $limit;

        if ($query && trim($query) !== '') {
            $sql = "
                SELECT d.id, d.title, d.subtitle, d.location, d.event_date,
                       d.document_type, d.addressee, d.words_count, d.volume_id, d.slug,
                       LEFT(d.content, 250) AS snippet,
                       ts_rank(d.search_vector, websearch_to_tsquery('polish', :query)) AS rank
                FROM documents d
                {$whereClause}
                ORDER BY rank DESC, d.event_date DESC NULLS LAST
                LIMIT :limit OFFSET :offset
            ";
        } else {
            $sql = "
                SELECT d.id, d.title, d.subtitle, d.location, d.event_date,
                       d.document_type, d.addressee, d.words_count, d.volume_id, d.slug,
                       LEFT(d.content, 250) AS snippet,
                       0 AS rank
                FROM documents d
                {$whereClause}
                ORDER BY d.event_date DESC NULLS LAST
                LIMIT :limit OFFSET :offset
            ";
        }

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        // Fetch volume info and tags for results
        if ($results) {
            $ids = array_column($results, 'id');
            $volumeIds = array_filter(array_unique(array_column($results, 'volume_id')));

            // Fetch volumes
            $volumes = [];
            if ($volumeIds) {
                $placeholders = implode(',', array_fill(0, count($volumeIds), '?'));
                $volumeRows = $conn->executeQuery(
                    "SELECT id, number, title FROM volumes WHERE id IN ({$placeholders})",
                    array_values($volumeIds)
                )->fetchAllAssociative();
                foreach ($volumeRows as $v) {
                    $volumes[$v['id']] = $v;
                }
            }

            // Fetch tags for all documents
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $tagRows = $conn->executeQuery(
                "SELECT dt.document_id, t.id, t.name, t.slug, t.color
                 FROM document_tags dt JOIN tags t ON t.id = dt.tag_id
                 WHERE dt.document_id IN ({$placeholders})",
                array_values($ids)
            )->fetchAllAssociative();

            $tagsByDoc = [];
            foreach ($tagRows as $tr) {
                $tagsByDoc[$tr['document_id']][] = $tr;
            }

            // Enrich results
            foreach ($results as &$row) {
                $row['volume'] = $volumes[$row['volume_id']] ?? null;
                $row['tags'] = $tagsByDoc[$row['id']] ?? [];
            }
        }

        return [
            'results' => $results,
            'total' => $total,
        ];
    }

    /**
     * Hybrid search: combines full-text (keyword) and semantic (vector) results.
     * Uses Reciprocal Rank Fusion (RRF) to merge rankings.
     */
    public function hybridSearch(
        string $query,
        array $queryEmbedding,
        ?int $volumeId = null,
        ?string $documentType = null,
        ?int $tagId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $page = 1,
        int $limit = 10,
    ): array {
        $conn = $this->getEntityManager()->getConnection();

        $filters = [$this->publishedFilter()];
        $params = [];

        if ($volumeId) {
            $filters[] = 'd.volume_id = :volume_id';
            $params['volume_id'] = $volumeId;
        }
        if ($documentType) {
            $filters[] = 'd.document_type = :document_type';
            $params['document_type'] = $documentType;
        }
        if ($tagId) {
            $filters[] = 'EXISTS (SELECT 1 FROM document_tags dt WHERE dt.document_id = d.id AND dt.tag_id = :tag_id)';
            $params['tag_id'] = $tagId;
        }
        if ($dateFrom) {
            $filters[] = 'd.event_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo) {
            $filters[] = 'd.event_date <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $filterClause = $filters ? 'AND ' . implode(' AND ', $filters) : '';
        $vectorStr = '[' . implode(',', $queryEmbedding) . ']';
        $params['query'] = trim($query);
        $params['embedding'] = $vectorStr;

        // RRF: Reciprocal Rank Fusion with k=60
        // Combines full-text rank position with vector similarity rank position
        $sql = "
            WITH fts AS (
                SELECT d.id,
                       ROW_NUMBER() OVER (ORDER BY ts_rank(d.search_vector, websearch_to_tsquery('polish', :query)) DESC) AS rn
                FROM documents d
                WHERE d.search_vector @@ websearch_to_tsquery('polish', :query)
                      {$filterClause}
            ),
            sem AS (
                SELECT d.id,
                       ROW_NUMBER() OVER (ORDER BY d.embedding <=> :embedding::vector) AS rn
                FROM documents d
                WHERE d.embedding IS NOT NULL
                      {$filterClause}
                LIMIT 200
            ),
            rrf AS (
                SELECT COALESCE(fts.id, sem.id) AS id,
                       COALESCE(1.0 / (60 + fts.rn), 0) AS fts_score,
                       COALESCE(1.0 / (60 + sem.rn), 0) AS sem_score,
                       COALESCE(1.0 / (60 + fts.rn), 0) + COALESCE(1.0 / (60 + sem.rn), 0) AS score
                FROM fts
                FULL OUTER JOIN sem ON fts.id = sem.id
            )
            SELECT COUNT(*) OVER() AS _total,
                   d.id, d.title, d.subtitle, d.location, d.event_date,
                   d.document_type, d.addressee, d.words_count, d.volume_id, d.slug,
                   LEFT(d.content, 250) AS snippet,
                   rrf.score AS rank,
                   rrf.fts_score, rrf.sem_score
            FROM rrf
            JOIN documents d ON d.id = rrf.id
            ORDER BY rrf.score DESC
            LIMIT :limit OFFSET :offset
        ";

        $offset = ($page - 1) * $limit;
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $total = 0;
        if ($results) {
            $total = (int) $results[0]['_total'];
            // Remove _total from results
            foreach ($results as &$row) {
                unset($row['_total']);
            }
            unset($row);

            // Enrich with volumes and tags (same as search())
            $ids = array_column($results, 'id');
            $volumeIds = array_filter(array_unique(array_column($results, 'volume_id')));

            $volumes = [];
            if ($volumeIds) {
                $placeholders = implode(',', array_fill(0, count($volumeIds), '?'));
                $volumeRows = $conn->executeQuery(
                    "SELECT id, number, title FROM volumes WHERE id IN ({$placeholders})",
                    array_values($volumeIds)
                )->fetchAllAssociative();
                foreach ($volumeRows as $v) {
                    $volumes[$v['id']] = $v;
                }
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $tagRows = $conn->executeQuery(
                "SELECT dt.document_id, t.id, t.name, t.slug, t.color
                 FROM document_tags dt JOIN tags t ON t.id = dt.tag_id
                 WHERE dt.document_id IN ({$placeholders})",
                array_values($ids)
            )->fetchAllAssociative();

            $tagsByDoc = [];
            foreach ($tagRows as $tr) {
                $tagsByDoc[$tr['document_id']][] = $tr;
            }

            foreach ($results as &$row) {
                $row['volume'] = $volumes[$row['volume_id']] ?? null;
                $row['tags'] = $tagsByDoc[$row['id']] ?? [];
            }
        }

        return [
            'results' => $results,
            'total' => $total,
        ];
    }

    public function getDocumentTypes(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $f = $this->publishedFilter();
        return $conn->executeQuery(
            "SELECT DISTINCT d.document_type FROM documents d WHERE d.document_type IS NOT NULL AND d.document_type != '' AND {$f} ORDER BY d.document_type"
        )->fetchFirstColumn();
    }

    public function getTotalCount(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $f = $this->publishedFilter();
        return (int) $conn->executeQuery("SELECT COUNT(*) FROM documents d WHERE {$f}")->fetchOne();
    }

    public function getTotalWords(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $f = $this->publishedFilter();
        return (int) $conn->executeQuery("SELECT COALESCE(SUM(d.words_count), 0) FROM documents d WHERE {$f}")->fetchOne();
    }

    public function getTotalChars(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $f = $this->publishedFilter();
        return (int) $conn->executeQuery("SELECT COALESCE(SUM(LENGTH(d.content)), 0) FROM documents d WHERE {$f}")->fetchOne();
    }
}
