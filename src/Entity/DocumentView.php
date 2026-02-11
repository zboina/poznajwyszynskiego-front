<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_views')]
class DocumentView
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id')]
    private int $userId;

    #[ORM\Column(name: 'document_id')]
    private int $documentId;

    #[ORM\Column(name: 'viewed_at')]
    private \DateTimeImmutable $viewedAt;

    public function __construct(int $userId, int $documentId)
    {
        $this->userId = $userId;
        $this->documentId = $documentId;
        $this->viewedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getDocumentId(): int
    {
        return $this->documentId;
    }

    public function getViewedAt(): \DateTimeImmutable
    {
        return $this->viewedAt;
    }
}
