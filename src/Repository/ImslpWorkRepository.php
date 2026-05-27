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

    public function findBySearch(string $q, string $instrumentation, string $style, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('w');
        $this->applyFilters($qb, $q, $instrumentation, $style);
        $qb->orderBy('w.composer')->addOrderBy('w.title')
           ->setFirstResult(($page - 1) * $perPage)
           ->setMaxResults($perPage);

        return $qb->getQuery()->getResult();
    }

    public function countBySearch(string $q, string $instrumentation, string $style): int
    {
        $qb = $this->createQueryBuilder('w')->select('COUNT(w.id)');
        $this->applyFilters($qb, $q, $instrumentation, $style);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function applyFilters(QueryBuilder $qb, string $q, string $instrumentation, string $style): void
    {
        if ($q !== '') {
            $qb->andWhere('(w.title LIKE :q OR w.composer LIKE :q OR w.catalogNumber LIKE :q)')
               ->setParameter('q', '%' . addcslashes($q, '%_\\') . '%');
        }
        if ($instrumentation !== '') {
            // Split on spaces/commas; each term must match as a whole word in tags,
            // OR as a substring in the verbose instrumentation field.
            $terms = array_filter(array_map('trim', preg_split('/[\s,]+/', $instrumentation)));
            foreach ($terms as $i => $term) {
                $escapedLike  = addcslashes($term, '%_\\');
                $escapedRegex = preg_quote($term, '/');
                $pt = 'instrT' . $i;
                $pi = 'instrI' . $i;
                // Tags: whole-word match via MySQL REGEXP word boundaries
                // Instrumentation: substring LIKE (verbose text, e.g. "viola da gamba")
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
}
