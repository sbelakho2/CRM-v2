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
     * Find or create profile for contact
     */
    public function findOrCreateForContact(int $contactId): PersonalizationProfile
    {
        $profile = $this->findByContactId($contactId);
        
        if (!$profile) {
            $profile = new PersonalizationProfile();
            $profile->setContactId($contactId);
            $this->getEntityManager()->persist($profile);
            $this->getEntityManager()->flush();
        }
        
        return $profile;
    }

    /**
     * Find high-engagement profiles
     */
    public function findHighEngagement(int $minOpens = 5, int $minReplies = 1): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.emailsOpened >= :minOpens')
            ->andWhere('p.emailsReplied >= :minReplies')
            ->setParameter('minOpens', $minOpens)
            ->setParameter('minReplies', $minReplies)
            ->orderBy('p.emailsReplied', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find profiles with embeddings (for similarity search)
     */
    public function findWithEmbeddings(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.featureEmbedding IS NOT NULL')
            ->andWhere('p.emailsOpened > 0')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get profiles by preferred tone
     */
    public function findByPreferredTone(string $tone): array
    {
        return $this->findBy(['preferredTone' => $tone]);
    }

    /**
     * Get aggregate statistics
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
        
        return $qb->getQuery()->getSingleResult();
    }
}
