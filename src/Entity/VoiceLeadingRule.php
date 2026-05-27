<?php

namespace App\Entity;

use App\Repository\VoiceLeadingRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VoiceLeadingRuleRepository::class)]
#[ORM\Table(name: 'voice_leading_rule')]
class VoiceLeadingRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $source = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $priority = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $definition = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $translation = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $implementation = '';

    #[ORM\Column(type: Types::JSON)]
    private array $citations = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getDefinition(): string
    {
        return $this->definition;
    }

    public function setDefinition(string $definition): static
    {
        $this->definition = $definition;
        return $this;
    }

    public function getTranslation(): string
    {
        return $this->translation;
    }

    public function setTranslation(string $translation): static
    {
        $this->translation = $translation;
        return $this;
    }

    public function getImplementation(): string
    {
        return $this->implementation;
    }

    public function setImplementation(string $implementation): static
    {
        $this->implementation = $implementation;
        return $this;
    }

    public function getCitations(): array
    {
        return $this->citations;
    }

    public function setCitations(array $citations): static
    {
        $this->citations = $citations;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }
}
