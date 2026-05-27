<?php

namespace App\Repository;

use App\Entity\VoiceLeadingRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VoiceLeadingRule>
 */
class VoiceLeadingRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VoiceLeadingRule::class);
    }

    /**
     * Returns all enabled rules ordered by priority ascending.
     *
     * @return VoiceLeadingRule[]
     */
    public function findActiveOrderedByPriority(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('r.priority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns a map of ruleName → ['citations' => [...], 'translation' => '...']
     * for all enabled rules. Used to attach DB citations to voice-leading trace steps.
     *
     * @return array<string, array{citations: array, translation: string}>
     */
    public function findCitationsMap(): array
    {
        $map = [];
        foreach ($this->findActiveOrderedByPriority() as $rule) {
            $map[$rule->getName()] = [
                'citations'   => $rule->getCitations(),
                'translation' => $rule->getTranslation(),
            ];
        }
        return $map;
    }
}
