<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'imslp_edition')]
#[ORM\Index(columns: ['work_id'], name: 'idx_imslp_edition_work_id')]
#[ORM\Index(columns: ['image_type'], name: 'idx_imslp_edition_image_type')]
class ImslpEdition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $workId = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $pageId = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $imageType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    public function getId(): ?int { return $this->id; }

    public function getWorkId(): int { return $this->workId; }
    public function setWorkId(int $v): void { $this->workId = $v; }

    public function getPageId(): int { return $this->pageId; }
    public function setPageId(int $v): void { $this->pageId = $v; }

    public function getImageType(): ?string { return $this->imageType; }
    public function setImageType(?string $v): void { $this->imageType = $v; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): void { $this->url = $v; }
}
