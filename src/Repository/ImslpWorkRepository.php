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
     * Returns composers whose name matches $q, with their work count.
     * @return array<array{name: string, work_count: int}>
     */
    public function findComposersLike(string $q, int $limit = 20): array
    {
        $ftq = $this->toFulltextQuery($q);
        $sql = 'SELECT composer AS name, COUNT(*) AS work_count
                FROM imslp_work
                WHERE %s
                GROUP BY composer
                ORDER BY work_count DESC
                LIMIT ' . (int) $limit;

        if ($ftq !== '') {
            return $this->getEntityManager()->getConnection()->fetchAllAssociative(
                sprintf($sql, 'MATCH(composer) AGAINST (? IN BOOLEAN MODE)'),
                [$ftq]
            );
        }

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf($sql, 'composer LIKE ?'),
            ['%' . addcslashes($q, '%_\\') . '%']
        );
    }

    // -------------------------------------------------------------------------
    // Title search
    // -------------------------------------------------------------------------

    public function findByTitleSearch(string $q, WorkFilters $f, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyTitleFilter($qb, $q);
        $this->applyFilters($qb, $f);
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByTitleSearch(string $q, WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(w.id)');
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
        $qb->orderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByComposer(string $composer, WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
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
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByFilters(WorkFilters $f): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(w.id)');
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

    /** Top $limit genres by frequency (first semicolon-delimited token of tags). */
    public function findDistinctGenres(int $limit = 60): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT TRIM(SUBSTRING_INDEX(tags, \';\', 1)) AS genre, COUNT(*) AS cnt
             FROM imslp_work
             WHERE tags IS NOT NULL AND tags != \'\'
               AND TRIM(SUBSTRING_INDEX(tags, \';\', 1)) REGEXP \'^[a-z]\'
             GROUP BY genre
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

        $ftq = $this->toFulltextQuery($q);

        if ($ftq !== '') {
            $qb->andWhere('MATCH_AGAINST(w.title, w.composer, w.catalogNumber, :ftq) > 0')
               ->setParameter('ftq', $ftq);
        } else {
            // All terms < 3 chars (e.g. "K.", "op") — fall back to LIKE
            $qb->andWhere('(w.title LIKE :q OR w.catalogNumber LIKE :q)')
               ->setParameter('q', '%' . addcslashes($q, '%_\\') . '%');
        }
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
        // Path A (exact section): abbreviation tokens (optional digit + ≤4 letters, e.g. "2fl",
        //   "bc") match a semicolon-delimited tag section containing EXACTLY those tokens.
        //   Uses REGEXP on tags (FULLTEXT can't handle 2-char tokens like "bc").
        //   For tagged works this is the sole match criterion.
        //
        // Path C (expanded abbreviations, untagged only): if the work has NO tags, abbreviation
        //   tokens are expanded to long-form equivalents ("fl"→"flute", "bc"→"continuo") and
        //   searched via FULLTEXT on the instrumentation text field. Works where both fields are
        //   NULL are included as truly unknown. Path C is NEVER applied to tagged works — a work
        //   tagged "fl bc" must not match a "2fl bc" search just because its instrumentation text
        //   also says "flute, continuo".
        //
        // Path B (FULLTEXT words): tokens ≥5 alpha chars (e.g. "dessus", "cembalo") matched
        //   against the instrumentation text field regardless of tags.
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
                        // Path C: untagged works fall back to long-form instrumentation text.
                        //   Restricted to detail-fetched works (detailSyncedAt IS NOT NULL) so
                        //   that works we have never examined don't silently pass the filter.
                        $orParts[] = '(REGEXP(w.tags, :instrExact) = 1'
                            . ' OR (w.detailSyncedAt IS NOT NULL AND (w.tags IS NULL OR w.tags = \'\') AND (MATCH_AGAINST(w.instrumentation, :instrExpanded) > 0 OR w.instrumentation IS NULL)))';
                    } else {
                        // No known expansion — tagged must match; detail-fetched untagged are unknown.
                        $orParts[] = '(REGEXP(w.tags, :instrExact) = 1 OR (w.detailSyncedAt IS NOT NULL AND (w.tags IS NULL OR w.tags = \'\')))';
                    }
                }

                // Path B — FULLTEXT on instrumentation for word tokens (≥5 alpha chars).
                // Restricted to detail-fetched works for the null pass-through.
                if (!empty($wordTokens)) {
                    $ftWords = implode(' ', array_map(
                        fn($t) => '+' . preg_replace('/[+\-><()"~*@]/', '', $t) . '*',
                        $wordTokens
                    ));
                    $orParts[] = '(MATCH_AGAINST(w.instrumentation, :instrWords) > 0 OR (w.detailSyncedAt IS NOT NULL AND w.instrumentation IS NULL))';
                    $qb->setParameter('instrWords', $ftWords);
                }

                if (!empty($orParts)) {
                    $qb->andWhere('(' . implode(' OR ', $orParts) . ')');
                }
            }
        }

        // style — works with NULL style are included (unknown ≠ wrong style)
        if ($f->style !== '') {
            $qb->andWhere('(w.pieceStyle IS NULL OR w.pieceStyle = :style)')
               ->setParameter('style', $f->style);
        }

        // genre — works with NULL tags are included
        if ($f->genre !== '') {
            $genreFtq = '+' . preg_replace('/[+\-><()"~*@]/', '', trim($f->genre)) . '*';
            $qb->andWhere('(w.tags IS NULL OR MATCH_AGAINST(w.tags, :genrePattern) > 0)')
               ->setParameter('genrePattern', $genreFtq);
        }

        // key — works with NULL key are included (97 % of works lack key data)
        if ($f->key !== '') {
            $qb->andWhere('(w.workKey IS NULL OR w.workKey LIKE :key)')
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
                (YEAR_EXTRACT(w.yearComposed) IS NOT NULL AND YEAR_EXTRACT(w.yearComposed) != 0 AND YEAR_EXTRACT(w.yearComposed) >= :yearFrom)
                OR (
                    (YEAR_EXTRACT(w.yearComposed) IS NULL OR YEAR_EXTRACT(w.yearComposed) = 0)
                    AND NOT EXISTS (
                        SELECT c1.id FROM App\Entity\ImslpComposer c1
                        WHERE c1.name = w.composer AND c1.diedYear IS NOT NULL AND c1.diedYear < :yearFrom
                    )
                )
            )')->setParameter('yearFrom', $f->yearFrom);
        }
        if ($f->yearTo !== null) {
            $qb->andWhere('(
                (YEAR_EXTRACT(w.yearComposed) IS NOT NULL AND YEAR_EXTRACT(w.yearComposed) != 0 AND YEAR_EXTRACT(w.yearComposed) <= :yearTo)
                OR (
                    (YEAR_EXTRACT(w.yearComposed) IS NULL OR YEAR_EXTRACT(w.yearComposed) = 0)
                    AND NOT EXISTS (
                        SELECT c2.id FROM App\Entity\ImslpComposer c2
                        WHERE c2.name = w.composer AND c2.bornYear IS NOT NULL AND c2.bornYear > :yearTo
                    )
                )
            )')->setParameter('yearTo', $f->yearTo);
        }
    }
}
