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
        // LIMIT must be inlined — DBAL cannot bind integers for LIMIT clauses in all drivers
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
    // Title-only search (used alongside composer cards)
    // -------------------------------------------------------------------------

    public function findByTitleSearch(string $q, string $instrumentation, string $style, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyTitleFilter($qb, $q);
        $this->applyInstrStyle($qb, $instrumentation, $style);
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByTitleSearch(string $q, string $instrumentation, string $style): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(w.id)');
        $this->applyTitleFilter($qb, $q);
        $this->applyInstrStyle($qb, $instrumentation, $style);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Composer browse (exact composer name, used after clicking a composer card)
    // -------------------------------------------------------------------------

    public function findByComposer(string $composer, string $instrumentation, string $style, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w')
            ->where('w.composer = :composer')
            ->setParameter('composer', $composer);
        $this->applyInstrStyle($qb, $instrumentation, $style);
        $qb->orderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByComposer(string $composer, string $instrumentation, string $style): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.composer = :composer')
            ->setParameter('composer', $composer);
        $this->applyInstrStyle($qb, $instrumentation, $style);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    // -------------------------------------------------------------------------
    // Instrumentation-only search (no q, no composer)
    // -------------------------------------------------------------------------

    public function findByInstrStyle(string $instrumentation, string $style, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyInstrStyle($qb, $instrumentation, $style);
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countByInstrStyle(string $instrumentation, string $style): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(w.id)');
        $this->applyInstrStyle($qb, $instrumentation, $style);

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

    public function findDistinctStyles(): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('DISTINCT w.pieceStyle')
            ->where('w.pieceStyle IS NOT NULL AND w.pieceStyle != :empty')
            ->setParameter('empty', '')
            ->orderBy('w.pieceStyle')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'pieceStyle');
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

    private function applyInstrStyle(QueryBuilder $qb, string $instrumentation, string $style): void
    {
        if ($instrumentation !== '') {
            $terms = array_filter(array_map('trim', preg_split('/[\s,]+/', $instrumentation)));
            foreach ($terms as $i => $term) {
                $escapedLike  = addcslashes($term, '%_\\');
                $escapedRegex = preg_quote($term, '/');
                $pt = 'instrT' . $i;
                $pi = 'instrI' . $i;
                $qb->andWhere("(w.tags REGEXP :$pt OR w.instrumentation LIKE :$pi)")
                   ->setParameter($pt, '(^|[^a-zA-Z0-9])' . $escapedRegex . '([^a-zA-Z0-9]|$)')
                   ->setParameter($pi, '%' . $escapedLike . '%');
            }
        }
        if ($style !== '') {
            $qb->andWhere('w.pieceStyle = :style')
               ->setParameter('style', $style);
        }
    }
}
