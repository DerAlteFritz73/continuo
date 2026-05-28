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
        $escaped = '%' . addcslashes($q, '%_\\') . '%';
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT composer AS name, COUNT(*) AS work_count
             FROM imslp_work
             WHERE composer LIKE ?
             GROUP BY composer
             ORDER BY work_count DESC
             LIMIT ' . (int) $limit,
            [$escaped]
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

    private function applyTitleFilter(QueryBuilder $qb, string $q): void
    {
        if ($q !== '') {
            $qb->andWhere('(w.title LIKE :q OR w.catalogNumber LIKE :q)')
               ->setParameter('q', '%' . addcslashes($q, '%_\\') . '%');
        }
    }

    private function applyFilters(QueryBuilder $qb, WorkFilters $f): void
    {
        // instrumentation — word-boundary REGEXP on tags, LIKE fallback on instrumentation text
        if ($f->instrumentation !== '') {
            $terms = array_filter(array_map('trim', preg_split('/[\s,]+/', $f->instrumentation)));
            foreach ($terms as $i => $term) {
                $escapedLike  = addcslashes($term, '%_\\');
                $escapedRegex = preg_quote($term, '/');
                $pt = 'instrT' . $i;
                $pi = 'instrI' . $i;
                $qb->andWhere("(REGEXP(w.tags, :$pt) = 1 OR w.instrumentation LIKE :$pi)")
                   ->setParameter($pt, '(^|[^a-zA-Z0-9])' . $escapedRegex . '([^a-zA-Z0-9]|$)')
                   ->setParameter($pi, '%' . $escapedLike . '%');
            }
        }

        // style
        if ($f->style !== '') {
            $qb->andWhere('w.pieceStyle = :style')
               ->setParameter('style', $f->style);
        }

        // genre — must appear as a semicolon-delimited token in tags
        if ($f->genre !== '') {
            $escapedRegex = preg_quote(trim($f->genre), '/');
            $qb->andWhere('REGEXP(w.tags, :genrePattern) = 1')
               ->setParameter('genrePattern', '(^|;\s*)' . $escapedRegex . '(\s*;|$)');
        }

        // key — fuzzy match
        if ($f->key !== '') {
            $qb->andWhere('w.workKey LIKE :key')
               ->setParameter('key', '%' . addcslashes($f->key, '%_\\') . '%');
        }

        // year range — extract first 4-digit number from year_composed string
        if ($f->yearFrom !== null) {
            $qb->andWhere('YEAR_EXTRACT(w.yearComposed) >= :yearFrom AND YEAR_EXTRACT(w.yearComposed) > 0')
               ->setParameter('yearFrom', $f->yearFrom);
        }
        if ($f->yearTo !== null) {
            $qb->andWhere('YEAR_EXTRACT(w.yearComposed) <= :yearTo AND YEAR_EXTRACT(w.yearComposed) > 0')
               ->setParameter('yearTo', $f->yearTo);
        }
    }
}
