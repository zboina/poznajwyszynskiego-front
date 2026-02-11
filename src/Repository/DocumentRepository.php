<?php

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
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

        $where = [];
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

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countSql = "SELECT COUNT(*) FROM documents d {$whereClause}";
        $total = (int) $conn->executeQuery($countSql, $params)->fetchOne();

        // Fetch results with headline if query provided
        $offset = ($page - 1) * $limit;

        if ($query && trim($query) !== '') {
            $sql = "
                SELECT d.id, d.title, d.subtitle, d.location, d.event_date,
                       d.document_type, d.addressee, d.words_count, d.volume_id,
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
                       d.document_type, d.addressee, d.words_count, d.volume_id,
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

    public function getDocumentTypes(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        return $conn->executeQuery(
            "SELECT DISTINCT document_type FROM documents WHERE document_type IS NOT NULL AND document_type != '' ORDER BY document_type"
        )->fetchFirstColumn();
    }

    public function getTotalCount(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        return (int) $conn->executeQuery("SELECT COUNT(*) FROM documents")->fetchOne();
    }

    public function getTotalWords(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        return (int) $conn->executeQuery("SELECT COALESCE(SUM(words_count), 0) FROM documents")->fetchOne();
    }

    public function getTotalChars(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        return (int) $conn->executeQuery("SELECT COALESCE(SUM(LENGTH(content)), 0) FROM documents")->fetchOne();
    }
}
