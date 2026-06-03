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

    #[ORM\Column(name: 'year_composed_int', type: Types::INTEGER, nullable: true)]
    private ?int $yearComposedInt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $yearPublished = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tags = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pageType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $movements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $genreCats = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $alternativeTitle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $averageDuration = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $librettist = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dedication = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstPerformance = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $composerId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $firstPerfDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstPerfLocation = null;

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

    public function getYearComposedInt(): ?int { return $this->yearComposedInt; }
    public function setYearComposedInt(?int $v): void { $this->yearComposedInt = $v; }

    public function getYearPublished(): ?string { return $this->yearPublished; }
    public function setYearPublished(?string $v): void { $this->yearPublished = $v; }

    public function getTags(): ?string { return $this->tags; }
    public function setTags(?string $v): void { $this->tags = $v; }

    public function getPageType(): ?string { return $this->pageType; }
    public function setPageType(?string $v): void { $this->pageType = $v; }

    public function getMovements(): ?string { return $this->movements; }
    public function setMovements(?string $v): void { $this->movements = $v; }

    public function getGenreCats(): ?string { return $this->genreCats; }
    public function setGenreCats(?string $v): void { $this->genreCats = $v; }

    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $v): void { $this->language = $v; }

    public function getAlternativeTitle(): ?string { return $this->alternativeTitle; }
    public function setAlternativeTitle(?string $v): void { $this->alternativeTitle = $v; }

    public function getAverageDuration(): ?string { return $this->averageDuration; }
    public function setAverageDuration(?string $v): void { $this->averageDuration = $v; }

    public function getLibrettist(): ?string { return $this->librettist; }
    public function setLibrettist(?string $v): void { $this->librettist = $v; }

    public function getDedication(): ?string { return $this->dedication; }
    public function setDedication(?string $v): void { $this->dedication = $v; }

    public function getFirstPerformance(): ?string { return $this->firstPerformance; }
    public function setFirstPerformance(?string $v): void { $this->firstPerformance = $v; }

    public function getComposerId(): ?int { return $this->composerId; }
    public function setComposerId(?int $v): void { $this->composerId = $v; }

    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $v): void { $this->durationSeconds = $v; }

    public function getFirstPerfDate(): ?string { return $this->firstPerfDate; }
    public function setFirstPerfDate(?string $v): void { $this->firstPerfDate = $v; }

    public function getFirstPerfLocation(): ?string { return $this->firstPerfLocation; }
    public function setFirstPerfLocation(?string $v): void { $this->firstPerfLocation = $v; }

    public function getFilesJson(): ?array { return $this->filesJson; }
    public function setFilesJson(?array $v): void { $this->filesJson = $v; }

    public function getDetailSyncedAt(): ?\DateTimeInterface { return $this->detailSyncedAt; }
    public function setDetailSyncedAt(?\DateTimeInterface $v): void { $this->detailSyncedAt = $v; }

    public function getSyncedAt(): \DateTimeInterface { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeInterface $v): void { $this->syncedAt = $v; }

    public function hasDetail(): bool { return $this->detailSyncedAt !== null; }
}
