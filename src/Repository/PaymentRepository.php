<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function save(Payment $payment, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($payment);
        if ($flush) {
            $em->flush();
        }
    }

    public function findOneByExternalId(string $externalId): ?Payment
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    /** @return Payment[] */
    public function findByUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['createdAt' => 'DESC']);
    }
}
