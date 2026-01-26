<?php

namespace App\Repository;

use App\Entity\SpintaxTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpintaxTemplate>
 */
class SpintaxTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpintaxTemplate::class);
    }

    /**
     * Find active templates by type
     */
    public function findActiveByType(string $templateType = 'email'): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.templateType = :type')
            ->andWhere('t.active = true')
            ->setParameter('type', $templateType)
            ->orderBy('t.timesUsed', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find best performing template
     */
    public function findBestPerforming(string $templateType = 'email'): ?SpintaxTemplate
    {
        $templates = $this->findActiveByType($templateType);
        
        if (empty($templates)) {
            return null;
        }

        // Sort by reply rate, then open rate
        usort($templates, function($a, $b) {
            if ($a->getReplyRate() !== $b->getReplyRate()) {
                return $b->getReplyRate() <=> $a->getReplyRate();
            }
            return $b->getOpenRate() <=> $a->getOpenRate();
        });
        
        return $templates[0];
    }

    /**
     * Get a random active template
     */
    public function findRandomActive(string $templateType = 'email'): ?SpintaxTemplate
    {
        $templates = $this->findActiveByType($templateType);
        
        if (empty($templates)) {
            return null;
        }
        
        return $templates[array_rand($templates)];
    }

    public function save(SpintaxTemplate $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
