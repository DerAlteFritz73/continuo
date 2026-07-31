<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(columns: ['created_at'], name: 'idx_comment_created_at')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'comment.error.email_required')]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, message: 'comment.error.email_invalid')]
    #[Assert\Length(max: 180, maxMessage: 'comment.error.email_invalid')]
    private string $email = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'comment.error.body_required')]
    #[Assert\Length(max: 5000, maxMessage: 'comment.error.body_too_long')]
    private string $body = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Short commit hash the visitor was looking at when they wrote this. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $appVersion = null;

    /** Whether the notification e-mail went out; null = not attempted yet. */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $notified = null;

    /** Transport error message when the notification failed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notifyError = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): void { $this->email = trim($v); }

    public function getBody(): string { return $this->body; }
    public function setBody(string $v): void { $this->body = trim($v); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $v): void { $this->locale = $v; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $v): void { $this->ipAddress = $v; }

    public function getAppVersion(): ?string { return $this->appVersion; }
    public function setAppVersion(?string $v): void { $this->appVersion = $v; }

    public function isNotified(): ?bool { return $this->notified; }
    public function setNotified(?bool $v): void { $this->notified = $v; }

    public function getNotifyError(): ?string { return $this->notifyError; }
    public function setNotifyError(?string $v): void
    {
        $this->notifyError = $v === null ? null : mb_substr($v, 0, 2000);
    }
}
