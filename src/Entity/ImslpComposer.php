<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'imslp_composer')]
#[ORM\Index(columns: ['name'], name: 'idx_imslp_composer_name')]
class ImslpComposer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 512, unique: true)]
    private string $imslpId = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 512)]
    private string $permlink = '';

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $bornYear = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $diedYear = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getImslpId(): string { return $this->imslpId; }
    public function setImslpId(string $v): void { $this->imslpId = $v; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): void { $this->name = $v; }
    public function getPermlink(): string { return $this->permlink; }
    public function setPermlink(string $v): void { $this->permlink = $v; }
    public function getBornYear(): ?int { return $this->bornYear; }
    public function setBornYear(?int $v): void { $this->bornYear = $v; }
    public function getDiedYear(): ?int { return $this->diedYear; }
    public function setDiedYear(?int $v): void { $this->diedYear = $v; }
    public function getSyncedAt(): \DateTimeInterface { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeInterface $v): void { $this->syncedAt = $v; }
}
