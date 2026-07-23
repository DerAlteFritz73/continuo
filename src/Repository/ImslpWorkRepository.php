<?php

namespace App\Repository;

use App\Entity\ImslpWork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class ImslpWorkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImslpWork::class);
    }

    // -------------------------------------------------------------------------
    // Composer-aggregated search (for composer cards)
    // -------------------------------------------------------------------------

    /**
     * Returns composers whose name matches $q and who have at least one work
     * matching $f, with the count of those matching works.
     * @return array<array{name: string, work_count: int}>
     */
    public function findComposersLike(string $q, WorkFilters $f, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('w')
            ->select('w.composer AS name, COUNT(w.id) AS work_count')
            ->groupBy('w.composer')
            ->orderBy('work_count', 'DESC')
            ->setMaxResults($limit);

        $ftq = $this->toFulltextQuery($q);
        if ($ftq !== '') {
            $qb->andWhere('MATCH_AGAINST(w.composer, :compFtq) > 0')
               ->setParameter('compFtq', $ftq);
        } else {
            $qb->andWhere('w.composer LIKE :compLike')
               ->setParameter('compLike', '%' . addcslashes($q, '%_\\') . '%');
        }

        $this->applyFilters($qb, $f);

        return $qb->getQuery()->getArrayResult();
    }

    // -------------------------------------------------------------------------
    // Title search
    // -------------------------------------------------------------------------

    public function findByTitleSearch(string $q, WorkFilters $f, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyTitleFilter($qb, $q);
        $this->applyFilters($qb, $f);
        // Use DISTINCT when joining editions to avoid duplicate work rows
        if (!$f->includeManuscripts) {
            $qb->distinct(true);
        }
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByTitleSearch(string $q, WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(DISTINCT w.id)');
        $this->applyTitleFilter($qb, $q);
        $this->applyFilters($qb, $f);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Composer browse
    // -------------------------------------------------------------------------

    public function findByComposer(string $composer, WorkFilters $f, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.composer = :composer')
            ->setParameter('composer', $composer);
        $this->applyFilters($qb, $f);
        // Use DISTINCT when joining editions to avoid duplicate work rows
        if (!$f->includeManuscripts) {
            $qb->distinct(true);
        }
        $qb->orderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByComposer(string $composer, WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(DISTINCT w.id)')
            ->where('w.composer = :composer')
            ->setParameter('composer', $composer);
        $this->applyFilters($qb, $f);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Filter-only search (no q, no exact composer)
    // -------------------------------------------------------------------------

    public function findByFilters(WorkFilters $f, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyFilters($qb, $f);
        // Use DISTINCT when joining editions to avoid duplicate work rows
        if (!$f->includeManuscripts) {
            $qb->distinct(true);
        }
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByFilters(WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(DISTINCT w.id)');
        $this->applyFilters($qb, $f);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Misc
    // -------------------------------------------------------------------------

    /** @return ImslpWork[] */
    public function findWithoutDetail(int $limit): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.detailSyncedAt IS NULL')
            ->orderBy('w.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countWithoutDetail(): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.detailSyncedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Works that were detail-fetched but have no tags (use categories as fallback). */
    public function findWithoutTags(int $limit): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('(w.tags IS NULL OR w.tags = :empty)')
            ->setParameter('empty', '')
            ->orderBy('w.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countWithoutTags(): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('(w.tags IS NULL OR w.tags = :empty)')
            ->setParameter('empty', '')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Works that have been detail-fetched but have no genre_cats yet. */
    public function findWithoutGenreCats(int $limit): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('w.genreCats IS NULL')
            ->orderBy('w.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countWithoutGenreCats(): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('w.genreCats IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Works detail-synced before the schema expansion on 2026-05-31 — need one re-fetch to pick up all new fields. */
    public function findWithoutAllFields(int $limit): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('w.detailSyncedAt < :cutoff')
            ->setParameter('cutoff', '2026-05-31 00:00:00')
            ->orderBy('w.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Find works by page IDs (used for async message handling). */
    public function findByPageIds(array $pageIds): array
    {
        if (empty($pageIds)) return [];
        return $this->createQueryBuilder('w')
            ->where('w.pageId IN (:pageIds)')
            ->setParameter('pageIds', $pageIds)
            ->orderBy('w.id')
            ->getQuery()
            ->getResult();
    }

    public function countWithoutAllFields(): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.detailSyncedAt IS NOT NULL')
            ->andWhere('w.detailSyncedAt < :cutoff')
            ->setParameter('cutoff', '2026-05-31 00:00:00')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Distinct non-empty edition types ordered by frequency. */
    /** Distinct non-empty language values ordered by frequency. */
    public function findDistinctLanguages(): array
    {
        // Language field can hold comma-separated values like "German, Latin".
        // Unnest via cross-join numbers table (most works have ≤ 3 languages).
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(language, \',\', n), \',\', -1)) AS lang,
                    COUNT(*) AS cnt
             FROM imslp_work
             CROSS JOIN (SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) nums
             WHERE language IS NOT NULL AND language != \'\'
               AND CHAR_LENGTH(language) - CHAR_LENGTH(REPLACE(language, \',\', \'\')) >= n - 1
             GROUP BY lang
             HAVING lang != \'\'
             ORDER BY cnt DESC'
        );

        return array_column($rows, 'lang');
    }

    /** Top $limit genres by occurrence count across all genre_cats values. */
    public function findDistinctGenres(int $limit = 60): array
    {
        // Cross-join against a small numbers table to unnest the semicolon-delimited genre_cats.
        // Supports up to 15 genres per work (more than any real IMSLP page has).
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(genre_cats, \';\', n), \';\', -1)) AS genre,
                    COUNT(*) AS cnt
             FROM imslp_work
             CROSS JOIN (
               SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
               UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
               UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15
             ) nums
             WHERE genre_cats IS NOT NULL AND genre_cats != \'\'
               AND CHAR_LENGTH(genre_cats) - CHAR_LENGTH(REPLACE(genre_cats, \';\', \'\')) >= n - 1
             GROUP BY genre
             HAVING genre != \'\'
             ORDER BY cnt DESC
             LIMIT ' . (int) $limit
        );

        return array_column($rows, 'genre');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Converts a plain search string into a FULLTEXT boolean mode query.
     * Each word >= 3 chars gets a + (required) prefix and * (prefix-wildcard) suffix.
     * Returns '' if no valid terms exist (caller should fall back to LIKE).
     */
    private function toFulltextQuery(string $q): string
    {
        $terms = array_values(array_filter(
            preg_split('/\s+/', trim($q)) ?: [],
            fn(string $t) => strlen($t) >= 3
        ));

        if (empty($terms)) {
            return '';
        }

        return implode(' ', array_map(
            fn(string $t) => '+' . preg_replace('/[+\-><()"~*@]/', '', $t) . '*',
            $terms
        ));
    }

    /**
     * Builds a MariaDB REGEXP pattern that matches a semicolon-delimited tag section
     * containing EXACTLY the given tokens (in any order, no extra instrument tokens).
     */
    private function buildExactSectionRegex(array $tokens): string
    {
        $escaped = array_map(fn($t) => preg_quote($t), $tokens);

        if (count($tokens) === 1) {
            return '(^|;)[ \t]*' . $escaped[0] . '[ \t]*(;|$)';
        }

        if (count($tokens) > 4) {
            return '(^|;)[ \t]*' . implode('[ \t]+', $escaped) . '[ \t]*(;|$)';
        }

        $alts = array_map(
            fn($perm) => implode('[ \t]+', $perm),
            $this->permutations($escaped)
        );

        return '(^|;)[ \t]*(' . implode('|', $alts) . ')[ \t]*(;|$)';
    }

    private function permutations(array $items): array
    {
        if (count($items) <= 1) return [$items];
        $result = [];
        foreach ($items as $i => $item) {
            $rest = array_values(array_filter($items, fn($k) => $k !== $i, ARRAY_FILTER_USE_KEY));
            foreach ($this->permutations($rest) as $perm) {
                $result[] = array_merge([$item], $perm);
            }
        }
        return $result;
    }

    /**
     * Expands abbreviation tokens (e.g. "2fl", "bc") to long-form FULLTEXT search terms
     * (e.g. "flute", "continuo"). Strips leading digits before lookup. Deduplicates.
     * Returns only terms present in ABBR_TO_LONG; unrecognised tokens are skipped.
     */
    private function expandAbbreviations(array $abbrTokens): array
    {
        $expanded = [];
        foreach ($abbrTokens as $token) {
            $key = strtolower(ltrim($token, '0123456789'));
            if (isset(self::ABBR_TO_LONG[$key])) {
                $term = self::ABBR_TO_LONG[$key];
                if (!in_array($term, $expanded, true)) {
                    $expanded[] = $term;
                }
            }
        }
        return $expanded;
    }

    /**
     * Title filter: FULLTEXT on (title, composer, catalog_number) for queries with
     * terms >= 3 chars; falls back to LIKE for very short queries (e.g. "K.", "op").
     */
    private function applyTitleFilter(QueryBuilder $qb, string $q): void
    {
        if ($q === '') {
            return;
        }

        // For common search queries, prefer LIKE over MATCH AGAINST for better cold-cache performance.
        // MATCH AGAINST can be slow on full-text index cold cache, while LIKE is consistent.
        // This is especially beneficial for frequently-searched short queries (composers, common works).
        $useLike = strlen($q) < 5; // Queries shorter than 5 chars tend to match many results anyway

        if (!$useLike) {
            $ftq = $this->toFulltextQuery($q);
            if ($ftq !== '') {
                $qb->andWhere('MATCH_AGAINST(w.title, w.composer, w.catalogNumber, :ftq) > 0')
                   ->setParameter('ftq', $ftq);
                return;
            }
        }

        // Fall back to LIKE for short queries or when no fulltext terms available
        $qb->andWhere('(w.title LIKE :q OR w.catalogNumber LIKE :q)')
           ->setParameter('q', '%' . addcslashes($q, '%_\\') . '%');
    }

    // Maps IMSLP abbreviations to long-form search terms used against the instrumentation text field.
    private const ABBR_TO_LONG = [
        'fl'   => 'flute',
        'flt'  => 'flute',
        'pic'  => 'piccolo',
        'ob'   => 'oboe',
        'ca'   => 'anglais',
        'cl'   => 'clarinet',
        'bn'   => 'bassoon',
        'fag'  => 'bassoon',
        'cbn'  => 'contrabassoon',
        'hn'   => 'horn',
        'cor'  => 'horn',
        'tp'   => 'trumpet',
        'tpt'  => 'trumpet',
        'tb'   => 'trombone',
        'tba'  => 'tuba',
        'vn'   => 'violin',
        'va'   => 'viola',
        'vc'   => 'cello',
        'cb'   => 'bass',
        'bc'   => 'continuo',
        'hpd'  => 'harpsichord',
        'cem'  => 'cembalo',
        'org'  => 'organ',
        'pf'   => 'piano',
        'pno'  => 'piano',
        'kbd'  => 'keyboard',
        'rec'  => 'recorder',
        'lute' => 'lute',
        'gt'   => 'guitar',
        'vdg'  => 'gamba',
        'gam'  => 'gamba',
        'str'  => 'strings',
        'sop'  => 'soprano',
        'mez'  => 'mezzo',
        'alt'  => 'alto',
        'ten'  => 'tenor',
        'bar'  => 'baritone',
        'bas'  => 'bass',
    ];

    private function applyFilters(QueryBuilder $qb, WorkFilters $f): void
    {
        // instrumentation search — two paths:
        //
        // Path A (exact section): abbreviation tokens (e.g. "2fl", "bc") match a
        //   semicolon-delimited tag section containing EXACTLY those tokens.
        //   Uses REGEXP on tags (FULLTEXT can't handle 2-char tokens like "bc").
        //
        // Path C (expanded, untagged only): if the work has NO tags, abbreviation tokens
        //   are expanded ("fl"→"flute", "bc"→"continuo") and matched via FULLTEXT on the
        //   instrumentation text field. A work with no tags AND no instrumentation text is
        //   excluded — having been fetched but yielding no data is not a match.
        //
        // Path B (FULLTEXT words): tokens ≥5 alpha chars matched against instrumentation text.
        if ($f->instrumentation !== '') {
            $normalised = preg_replace('/\b(\d+)\s+([a-z]{1,4})\b/i', '$1$2', trim($f->instrumentation));
            $tokens     = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $normalised))));

            if (!empty($tokens)) {
                $abbrTokens = array_values(array_filter($tokens, fn($t) => preg_match('/^\d*[a-z]{1,4}$/i', $t)));
                $wordTokens = array_values(array_filter($tokens, fn($t) => preg_match('/^[a-z]{5,}$/i',     $t)));

                $orParts = [];

                if (!empty($abbrTokens)) {
                    $qb->setParameter('instrExact', $this->buildExactSectionRegex($abbrTokens));
                    $expanded = $this->expandAbbreviations($abbrTokens);

                    if (!empty($expanded)) {
                        $ftExpanded = implode(' ', array_map(fn($t) => '+' . $t . '*', $expanded));
                        $qb->setParameter('instrExpanded', $ftExpanded);
                        // Path A: tagged works must match the exact section in tags.
                        // Path C: untagged works must match the instrumentation text — no text = no match.
                        $orParts[] = '(REGEXP(w.tags, :instrExact) = 1'
                            . ' OR ((w.tags IS NULL OR w.tags = \'\') AND MATCH_AGAINST(w.instrumentation, :instrExpanded) > 0))';
                    } else {
                        // No known expansion — only exact tag match counts.
                        $orParts[] = 'REGEXP(w.tags, :instrExact) = 1';
                    }
                }

                // Path B — word-style tokens must match instrumentation text; null = no match.
                if (!empty($wordTokens)) {
                    $ftWords = implode(' ', array_map(
                        fn($t) => '+' . preg_replace('/[+\-><()"~*@]/', '', $t) . '*',
                        $wordTokens
                    ));
                    $orParts[] = 'MATCH_AGAINST(w.instrumentation, :instrWords) > 0';
                    $qb->setParameter('instrWords', $ftWords);
                }

                if (!empty($orParts)) {
                    $qb->andWhere('(' . implode(' OR ', $orParts) . ')');
                }
            }
        }

        // part count — the work's parsed part range must contain the requested count.
        //   A work stored as "4-6 voices" ([min=4,max=6]) matches a request for 5;
        //   an exact "4 voices" ([4,4]) matches only 4. Works with no parsed count
        //   (part_count_min IS NULL) are excluded.
        if ($f->partCount !== null) {
            $qb->andWhere('w.partCountMin IS NOT NULL AND w.partCountMin <= :partCount AND w.partCountMax >= :partCount')
               ->setParameter('partCount', $f->partCount);
        }

        // voice registers — two modes over the stored canonical multiset (ordered
        //   S→A→T→B with repetition, e.g. "SSATB"):
        //
        //   exact   (exactRegisters=true): the work's ensemble must EQUAL the request.
        //     "SSTTB" → only works whose voice_registers is exactly "SSTTB"
        //     (2 sopranos, 2 tenors, 1 bass, no altos, nothing more). Both sides are
        //     canonical, so a plain equality suffices.
        //
        //   contains (default): the work must have AT LEAST the requested multiplicity
        //     of each register — "≥k of X" is a LIKE on k repeated letters:
        //       "SB"    → contains S and B          (LIKE '%S%' AND '%B%')
        //       "SSATB" → ≥2 S, ≥1 A/T/B            (LIKE '%SS%' AND '%A%' AND '%T%' AND '%B%')
        //     extra registers (e.g. an alto) are allowed.
        if ($f->voiceRegisters !== '') {
            $clean = strtoupper(preg_replace('/[^SATBsatb]/', '', $f->voiceRegisters));
            if ($f->exactRegisters) {
                $qb->andWhere('w.voiceRegisters = :vregExact')
                   ->setParameter('vregExact', $clean);
            } else {
                $need = [];
                foreach (str_split($clean) as $ch) {
                    $need[$ch] = ($need[$ch] ?? 0) + 1;
                }
                $i = 0;
                foreach ($need as $ch => $k) {
                    $qb->andWhere("w.voiceRegisters LIKE :vreg$i")
                       ->setParameter("vreg$i", '%' . str_repeat($ch, $k) . '%');
                    $i++;
                }
            }
        }

        // style — only works with a known matching style; null = excluded
        if ($f->style !== '') {
            $qb->andWhere('w.pieceStyle = :style')
               ->setParameter('style', $f->style);
        }

        // genre — matched against genre_cats (IMSLP composer/genre categories); null = excluded
        if ($f->genre !== '') {
            $genreFtq = '+' . preg_replace('/[+\-><()"~*@]/', '', trim($f->genre)) . '*';
            $qb->andWhere('MATCH_AGAINST(w.genreCats, :genrePattern) > 0')
               ->setParameter('genrePattern', $genreFtq);
        }

        // language — LIKE match so "German, Latin" matches a search for "German"
        if ($f->language !== '') {
            $qb->andWhere('w.language LIKE :language')
               ->setParameter('language', '%' . addcslashes($f->language, '%_\\') . '%');
        }

        // key — only works with a known matching key; null = excluded
        if ($f->key !== '') {
            $qb->andWhere('w.workKey LIKE :key')
               ->setParameter('key', '%' . addcslashes($f->key, '%_\\') . '%');
        }

        // year range — two-path per bound:
        //   Path 1: year_composed is known → apply directly.
        //   Path 2: year_composed is unknown → use composer birth/death as bounds.
        //     yearFrom: exclude if composer's death year is known and earlier than yearFrom
        //               (they couldn't have been active after yearFrom).
        //     yearTo:   exclude if composer's birth year is known and later than yearTo
        //               (they weren't born yet — e.g. Abert b.1832 excluded from ≤1800).
        //   If both year_composed and composer dates are unknown, the work passes (inclusive).
        if ($f->yearFrom !== null) {
            $qb->andWhere('(
                (w.yearComposedInt IS NOT NULL AND w.yearComposedInt >= :yearFrom)
                OR (
                    w.yearComposedInt IS NULL
                    AND NOT EXISTS (
                        SELECT c1.id FROM App\Entity\ImslpComposer c1
                        WHERE c1.name = w.composer AND c1.diedYear IS NOT NULL AND c1.diedYear < :yearFrom
                    )
                )
            )')->setParameter('yearFrom', $f->yearFrom);
        }
        if ($f->yearTo !== null) {
            $qb->andWhere('(
                (w.yearComposedInt IS NOT NULL AND w.yearComposedInt <= :yearTo)
                OR (
                    w.yearComposedInt IS NULL
                    AND NOT EXISTS (
                        SELECT c2.id FROM App\Entity\ImslpComposer c2
                        WHERE c2.name = w.composer AND c2.bornYear IS NOT NULL AND c2.bornYear > :yearTo
                    )
                )
            )')->setParameter('yearTo', $f->yearTo);
        }

        // exclude manuscripts if not included
        if (!$f->includeManuscripts) {
            $qb->innerJoin('App\Entity\ImslpEdition', 'e', 'WITH', 'e.workId = w.id AND e.imageType != :manuscript')
               ->setParameter('manuscript', 'Manuscript');
        }
    }
}
