<?php

namespace App\Repository;

use App\Entity\PersonalizationProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalizationProfile>
 */
class PersonalizationProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalizationProfile::class);
    }

    /**
     * Find profile by contact ID
     */
    public function findByContactId(int $contactId): ?PersonalizationProfile
    {
        return $this->findOneBy(['contactId' => $contactId]);
    }

    /**
     * Find profile by company ID
     */
    public function findByCompanyId(int $companyId): ?PersonalizationProfile
    {
        return $this->findOneBy(['companyId' => $companyId]);
    }

    /**
     * Find or create profile for contact.
     *
     * Persists the new entity but does NOT flush.
     * The caller is responsible for calling flush() on the EntityManager.
     *
     * @return PersonalizationProfile
     */
    public function findOrCreateForContact(int $contactId): PersonalizationProfile
    {
        $profile = $this->findByContactId($contactId);
        
        if (!$profile) {
            $profile = new PersonalizationProfile();
            $profile->setContactId($contactId);
            $this->getEntityManager()->persist($profile);
        }
        
        return $profile;
    }

    /**
     * Find high-engagement profiles
          *
     * @return list<PersonalizationProfile>
     */
    public function findHighEngagement(int $minOpens = 5, int $minReplies = 1): array
    {
        /** @var list<PersonalizationProfile> $results */
        $results = $this->createQueryBuilder('p')
            ->where('p.emailsOpened >= :minOpens')
            ->andWhere('p.emailsReplied >= :minReplies')
            ->setParameter('minOpens', $minOpens)
            ->setParameter('minReplies', $minReplies)
            ->orderBy('p.emailsReplied', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find profiles with embeddings (for similarity search)
          *
     * @return list<PersonalizationProfile>
     */
    public function findWithEmbeddings(): array
    {
        /** @var list<PersonalizationProfile> $results */
        $results = $this->createQueryBuilder('p')
            ->where('p.featureEmbedding IS NOT NULL')
            ->andWhere('p.emailsOpened > 0')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Get profiles by preferred tone
          *
     * @return list<PersonalizationProfile>
     */
    public function findByPreferredTone(string $tone): array
    {
        return $this->findBy(['preferredTone' => $tone], null, 500);
    }

    /**
     * Get aggregate statistics
     *
     * @return array<string, int|float|string|null>
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                COUNT(p.id) as total,
                AVG(p.emailsOpened) as avgOpens,
                AVG(p.emailsReplied) as avgReplies,
                SUM(p.emailsOpened) as totalOpens,
                SUM(p.emailsReplied) as totalReplies
            ');

        /** @var array<string, int|float|string|null> $row */
        $row = $qb->getQuery()->getSingleResult();

        return $row;
    }
}
