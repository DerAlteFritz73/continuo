<?php

namespace App\Message;

final class FetchWorkDetailsMessage
{
    public function __construct(
        private readonly array $pageIds,
        private readonly bool $refetchNoTags = false,
        private readonly bool $fillGenres = false,
        private readonly bool $fillAll = false,
    ) {}

    public function getPageIds(): array
    {
        return $this->pageIds;
    }

    public function shouldRefetchNoTags(): bool
    {
        return $this->refetchNoTags;
    }

    public function shouldFillGenres(): bool
    {
        return $this->fillGenres;
    }

    public function shouldFillAll(): bool
    {
        return $this->fillAll;
    }
}
