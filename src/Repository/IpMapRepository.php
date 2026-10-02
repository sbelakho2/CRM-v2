<?php

namespace App\Repository;

use App\Entity\IpMap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IpMap>
 */
class IpMapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IpMap::class);
    }

    /**
     * Find valid mapping by IP address
     */
    public function findValidByIp(string $ipAddress): ?IpMap
    {
        /** @var IpMap|null $result */
        $result = $this->createQueryBuilder('im')
            ->andWhere('im.ipAddress = :ip')
            ->andWhere('im.expiresAt IS NULL OR im.expiresAt > :now')
            ->setParameter('ip', $ipAddress)
            ->setParameter('now', new \DateTime())
            ->orderBy('im.asof', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find all valid mappings
     *
     * @return list<IpMap>
     */
    public function findAllValid(): array
    {
        /** @var list<IpMap> $result */
        $result = $this->createQueryBuilder('im')
            ->andWhere('im.expiresAt IS NULL OR im.expiresAt > :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('im.organizationName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
