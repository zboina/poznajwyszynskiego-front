<?php

namespace App\Entity;

use App\Repository\RagQueryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One logged AI-assistant query, used for per-user usage metering so the
 * (real) generation cost stays bounded per access tier.
 */
#[ORM\Entity(repositoryClass: RagQueryRepository::class)]
#[ORM\Table(name: 'rag_query')]
#[ORM\Index(name: 'idx_rag_query_user_time', columns: ['user_id', 'created_at'])]
class RagQuery
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId;

    #[ORM\Column(name: 'volume_id', nullable: true)]
    private ?int $volumeId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeInterface $createdAt;

    public function __construct(int $userId, ?int $volumeId = null)
    {
        $this->userId = $userId;
        $this->volumeId = $volumeId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUserId(): int { return $this->userId; }
    public function getVolumeId(): ?int { return $this->volumeId; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
