<?php

namespace App\Repository;

use App\Entity\PersonalizationArchetype;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalizationArchetype>
 */
class PersonalizationArchetypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalizationArchetype::class);
    }

    /**
     * Find all active archetypes
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], null, 500);
    }

    /**
     * Find archetypes by industry
     */
    public function findByIndustry(string $industry): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.targetIndustry = :industry')
            ->andWhere('a.isActive = true')
            ->setParameter('industry', $industry)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find archetypes by role
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.targetRole = :role')
            ->andWhere('a.isActive = true')
            ->setParameter('role', $role)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find archetypes matching industry and role
     */
    public function findMatchingArchetypes(string $industry, string $role): array
    {
        // First try exact match
        $exact = $this->createQueryBuilder('a')
            ->where('a.targetIndustry = :industry')
            ->andWhere('a.targetRole = :role')
            ->andWhere('a.isActive = true')
            ->setParameter('industry', $industry)
            ->setParameter('role', $role)
            ->getQuery()
            ->getResult();

        if (!empty($exact)) {
            return $exact;
        }

        // Fall back to industry match
        return $this->findByIndustry($industry);
    }

    /**
     * Get archetype by name
     */
    public function findByName(string $name): ?PersonalizationArchetype
    {
        return $this->findOneBy(['archetypeName' => $name, 'isActive' => true]);
    }

    /**
     * Get archetypes as "fake" profiles for similarity matching
     * Returns array with profile-like structure for compatibility
     */
    public function getArchetypesAsProfiles(): array
    {
        $archetypes = $this->findAllActive();
        
        $profiles = [];
        foreach ($archetypes as $archetype) {
            $profiles[] = [
                'profile' => $archetype,
                'similarity' => 0.0, // Will be calculated
                'engagementScore' => $archetype->getSyntheticEngagementScore(),
                'isArchetype' => true,
            ];
        }
        
        return $profiles;
    }
}
