<?php

namespace App\Repository;

readonly class WorkFilters
{
    public function __construct(
        public string $instrumentation = '',
        public string $style           = '',
        public string $genre           = '',
        public string $key             = '',
        public string $language        = '',
        public bool   $includeManuscripts = true,
        public ?int   $yearFrom        = null,
        public ?int   $yearTo          = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->instrumentation === ''
            && $this->style           === ''
            && $this->genre           === ''
            && $this->key             === ''
            && $this->language        === ''
            && $this->includeManuscripts === true
            && $this->yearFrom        === null
            && $this->yearTo          === null;
    }
}
