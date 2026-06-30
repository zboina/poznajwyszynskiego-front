<?php

namespace App\Entity;

use App\Repository\RagAnswerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trwała, niezmienna treść odpowiedzi asystenta — zapisywana RAZ i współdzielona.
 * Klucz `contentHash` to ten sam hash, którym posługuje się cache (zakres + epoka
 * korpusu + znormalizowane pytanie), więc identyczne pytanie w tej samej epoce mapuje
 * się na jeden wiersz. Wiele wpisów historii (RagQuery) różnych użytkowników może
 * wskazywać na tę samą odpowiedź — stąd brak duplikacji treści w bazie.
 */
#[ORM\Entity(repositoryClass: RagAnswerRepository::class)]
#[ORM\Table(name: 'rag_answer')]
class RagAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'content_hash', length: 64, unique: true)]
    private string $contentHash;

    #[ORM\Column(type: 'text')]
    private string $question;

    #[ORM\Column(name: 'volume_id', nullable: true)]
    private ?int $volumeId = null;

    #[ORM\Column(type: 'text')]
    private string $answer;

    #[ORM\Column(type: 'json')]
    private array $citations = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeInterface $createdAt;

    public function __construct(string $contentHash, string $question, ?int $volumeId, string $answer, array $citations)
    {
        $this->contentHash = $contentHash;
        $this->question = $question;
        $this->volumeId = $volumeId;
        $this->answer = $answer;
        $this->citations = $citations;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getContentHash(): string { return $this->contentHash; }
    public function getQuestion(): string { return $this->question; }
    public function getVolumeId(): ?int { return $this->volumeId; }
    public function getAnswer(): string { return $this->answer; }
    public function getCitations(): array { return $this->citations; }
    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
}
