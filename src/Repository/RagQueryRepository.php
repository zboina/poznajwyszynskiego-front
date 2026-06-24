<?php

namespace App\Repository;

use App\Entity\RagQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RagQuery>
 */
class RagQueryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RagQuery::class);
    }

    public function log(int $userId, ?int $volumeId = null): void
    {
        $em = $this->getEntityManager();
        $em->persist(new RagQuery($userId, $volumeId));
        $em->flush();
    }

    /** Number of assistant queries by this user since the given moment. */
    public function countSince(int $userId, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->andWhere('q.userId = :uid')
            ->andWhere('q.createdAt >= :since')
            ->setParameter('uid', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
