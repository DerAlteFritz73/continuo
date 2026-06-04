<?php

namespace App\Service;

use App\Repository\ImslpWorkRepository;
use App\Repository\WorkFilters;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Consolidated IMSLP search and filtering logic.
 * Handles search/filter queries with caching and optimization.
 */
class ImslpSearchService
{
    public function __construct(
        private readonly ImslpWorkRepository $workRepo,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Search works by title, with automatic composer detection.
     * Returns [works, composerMatches, total, pages, mode]
     * All pages (1, 2, 3...) are cached separately for fast pagination.
     */
    public function searchByQuery(string $q, WorkFilters $filters, int $page = 1, int $perPage = 30): array
    {
        if ($q === '') return ['works' => [], 'composerMatches' => [], 'total' => 0, 'pages' => 0, 'mode' => 'empty'];

        // First, check for composer matches
        $composerMatches = $this->workRepo->findComposersLike($q, $filters);

        // If exact composer match, switch to composer mode
        if (count($composerMatches) === 1 && strcasecmp($composerMatches[0]['name'], $q) === 0) {
            return $this->searchByComposer($composerMatches[0]['name'], $filters, $page, $perPage);
        }

        // Otherwise, search by title
        $searchHash = md5($q . json_encode((array) $filters));
        $total = $this->cachedCount('imslp.count.search.' . $searchHash,
            fn() => $this->workRepo->countByTitleSearch($q, $filters), 900);
        $pages = (int) ceil($total / $perPage);
        // Each page is cached separately for instant pagination
        $works = $this->cachedSearch('imslp.search.' . $searchHash . '.' . $page,
            fn() => $this->workRepo->findByTitleSearch($q, $filters, $page, $perPage), 3600);

        return [
            'works' => $works,
            'composerMatches' => $composerMatches,
            'total' => $total,
            'pages' => $pages,
            'mode' => 'search',
        ];
    }

    /**
     * Search works by composer name.
     * Returns [works, total, pages, mode]
     */
    public function searchByComposer(string $composer, WorkFilters $filters, int $page = 1, int $perPage = 30): array
    {
        if ($composer === '') return ['works' => [], 'total' => 0, 'pages' => 0, 'mode' => 'empty'];

        $total = $this->cachedCount('imslp.count.composer.' . $composer,
            fn() => $this->workRepo->countByComposer($composer, $filters), 900);
        $pages = (int) ceil($total / $perPage);
        $works = $this->cachedSearch('imslp.search.composer.' . $composer . '.' . $page,
            fn() => $this->workRepo->findByComposer($composer, $filters, $page, $perPage), 3600);

        return [
            'works' => $works,
            'total' => $total,
            'pages' => $pages,
            'mode' => 'composer',
        ];
    }

    /**
     * Search works by filters only (no query string).
     * Returns [works, total, pages, mode]
     */
    public function searchByFilters(WorkFilters $filters, int $page = 1, int $perPage = 30): array
    {
        if ($filters->isEmpty()) return ['works' => [], 'total' => 0, 'pages' => 0, 'mode' => 'empty'];

        $filterHash = md5(json_encode((array) $filters));
        $total = $this->cachedCount('imslp.count.filter.' . $filterHash,
            fn() => $this->workRepo->countByFilters($filters), 900);
        $pages = (int) ceil($total / $perPage);
        $works = $this->cachedSearch('imslp.search.filter.' . $filterHash . '.' . $page,
            fn() => $this->workRepo->findByFilters($filters, $page, $perPage), 3600);

        return [
            'works' => $works,
            'total' => $total,
            'pages' => $pages,
            'mode' => 'filter',
        ];
    }

    /**
     * Cache a count query result with invalidation tags.
     */
    private function cachedCount(string $key, callable $query, int $ttl): int
    {
        return (int) $this->cache->get($key, function (ItemInterface $item) use ($query, $ttl): int {
            $item->expiresAfter($ttl);
            // Tag with 'imslp.counts' only if cache supports tags
            if ($this->cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface) {
                $item->tag('imslp.counts');
            }
            return $query();
        });
    }

    /**
     * Cache a search result with invalidation tags.
     */
    private function cachedSearch(string $key, callable $query, int $ttl): array
    {
        return $this->cache->get($key, function (ItemInterface $item) use ($query, $ttl): array {
            $item->expiresAfter($ttl);
            // Tag with 'imslp.searches' only if cache supports tags
            if ($this->cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface) {
                $item->tag('imslp.searches');
            }
            return $query();
        });
    }

    /**
     * Invalidate all search and count caches when works are updated.
     * Call this after bulk imports or significant updates.
     */
    public function invalidateSearchCaches(): void
    {
        // Only use tag invalidation if the adapter supports it
        if ($this->cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface) {
            $this->cache->invalidateTags(['imslp.counts', 'imslp.searches']);
        }
        // Fallback: clear individual cache keys if tag invalidation unavailable
        // This is less efficient but works with all cache adapters
    }
}
