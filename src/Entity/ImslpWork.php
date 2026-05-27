<?php

namespace App\Entity;

use App\Repository\ImslpWorkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImslpWorkRepository::class)]
#[ORM\Table(name: 'imslp_work')]
#[ORM\Index(columns: ['composer'], name: 'idx_imslp_work_composer')]
#[ORM\Index(columns: ['page_id'], name: 'idx_imslp_work_page_id')]
class ImslpWork
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 512, unique: true)]
    private string $imslpId = '';

    #[ORM\Column(length: 512)]
    private string $title = '';

    #[ORM\Column(length: 255)]
    private string $composer = '';

    #[ORM\Column(length: 150)]
    private string $catalogNumber = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $pageId = 0;

    #[ORM\Column(length: 512)]
    private string $permlink = '';

    // Fields populated by detail fetch (MediaWiki API)

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workKey = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $instrumentation = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pieceStyle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $yearComposed = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $yearPublished = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tags = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pageType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $movements = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $filesJson = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $detailSyncedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getImslpId(): string { return $this->imslpId; }
    public function setImslpId(string $v): void { $this->imslpId = $v; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): void { $this->title = $v; }

    public function getComposer(): string { return $this->composer; }
    public function setComposer(string $v): void { $this->composer = $v; }

    public function getCatalogNumber(): string { return $this->catalogNumber; }
    public function setCatalogNumber(string $v): void { $this->catalogNumber = $v; }

    public function getPageId(): int { return $this->pageId; }
    public function setPageId(int $v): void { $this->pageId = $v; }

    public function getPermlink(): string { return $this->permlink; }
    public function setPermlink(string $v): void { $this->permlink = $v; }

    public function getWorkKey(): ?string { return $this->workKey; }
    public function setWorkKey(?string $v): void { $this->workKey = $v; }

    public function getInstrumentation(): ?string { return $this->instrumentation; }
    public function setInstrumentation(?string $v): void { $this->instrumentation = $v; }

    public function getPieceStyle(): ?string { return $this->pieceStyle; }
    public function setPieceStyle(?string $v): void { $this->pieceStyle = $v; }

    public function getYearComposed(): ?string { return $this->yearComposed; }
    public function setYearComposed(?string $v): void { $this->yearComposed = $v; }

    public function getYearPublished(): ?string { return $this->yearPublished; }
    public function setYearPublished(?string $v): void { $this->yearPublished = $v; }

    public function getTags(): ?string { return $this->tags; }
    public function setTags(?string $v): void { $this->tags = $v; }

    public function getPageType(): ?string { return $this->pageType; }
    public function setPageType(?string $v): void { $this->pageType = $v; }

    public function getMovements(): ?string { return $this->movements; }
    public function setMovements(?string $v): void { $this->movements = $v; }

    public function getFilesJson(): ?array { return $this->filesJson; }
    public function setFilesJson(?array $v): void { $this->filesJson = $v; }

    public function getDetailSyncedAt(): ?\DateTimeInterface { return $this->detailSyncedAt; }
    public function setDetailSyncedAt(?\DateTimeInterface $v): void { $this->detailSyncedAt = $v; }

    public function getSyncedAt(): \DateTimeInterface { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeInterface $v): void { $this->syncedAt = $v; }

    public function hasDetail(): bool { return $this->detailSyncedAt !== null; }
}
