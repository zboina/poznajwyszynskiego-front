<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'volumes')]
class Volume
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $number = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'year_from', nullable: true)]
    private ?int $yearFrom = null;

    #[ORM\Column(name: 'year_to', nullable: true)]
    private ?int $yearTo = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = null;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'volume')]
    private Collection $documents;

    public function __construct()
    {
        $this->documents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getYearFrom(): ?int
    {
        return $this->yearFrom;
    }

    public function getYearTo(): ?int
    {
        return $this->yearTo;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->status === 'opublikowany';
    }

    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function __toString(): string
    {
        return sprintf('Tom %s: %s', $this->number, $this->title);
    }
}
