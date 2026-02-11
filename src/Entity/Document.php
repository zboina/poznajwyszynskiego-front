<?php

namespace App\Entity;

use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'documents')]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $subtitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(name: 'event_date', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $eventDate = null;

    #[ORM\Column(name: 'document_type', length: 100, nullable: true)]
    private ?string $documentType = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $addressee = null;

    #[ORM\Column(name: 'words_count', nullable: true)]
    private ?int $wordsCount = null;

    #[ORM\ManyToOne(targetEntity: Volume::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(name: 'volume_id', referencedColumnName: 'id')]
    private ?Volume $volume = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'documents')]
    #[ORM\JoinTable(name: 'document_tags')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private Collection $tags;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSubtitle(): ?string
    {
        return $this->subtitle;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getEventDate(): ?\DateTimeInterface
    {
        return $this->eventDate;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function getAddressee(): ?string
    {
        return $this->addressee;
    }

    public function getWordsCount(): ?int
    {
        return $this->wordsCount;
    }

    public function getVolume(): ?Volume
    {
        return $this->volume;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }
}
