<?php

namespace App\Repository;

use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    public function countByStageAndPeriod(string $stage, \DateTimeInterface $startDate, \DateTimeInterface $endDate): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->andWhere('c.createdAt >= :start')
            ->andWhere('c.createdAt <= :end')
            ->setParameter('stage', $stage)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findBySectorAndTier(string $sector, string $tier)
    {
        return $this->createQueryBuilder('c')
            ->where('c.sector = :sector')
            ->andWhere('c.accountTier = :tier')
            ->setParameter('sector', $sector)
            ->setParameter('tier', $tier)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySector(string $sector): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.sector = :sector')
            ->setParameter('sector', $sector)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPipelineStage(string $stage): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->setParameter('stage', $stage)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Return all existing company website domains as a flat array.
     *
     * Used by GoogleDorkService to inject -site: exclusions into
     * search queries so that previously-found companies are pushed
     * out of search results, making room for new discoveries.
     *
     * @return string[] Root domains (e.g. ['ezz-elarab.com', 'aboulfotouh-egypt.com'])
     */
    public function findAllWebsiteDomains(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.website')
            ->where('c.website IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        $domains = [];
        foreach ($rows as $row) {
            $url = $row['website'] ?? '';
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $domain = preg_replace('/^www\./', '', strtolower($host));
                if ($domain) {
                    $domains[] = $domain;
                }
            }
        }
        return array_unique($domains);
    }

    /**
     * Return all company names for a given sector, for dynamic query expansion.
     *
     * @return string[]
     */
    public function findNamesBySector(string $sector): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.name')
            ->where('c.sector = :sector')
            ->setParameter('sector', $sector)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'name');
    }

    /**
     * Return company names for a given sector AND region.
     * Returns whatever same-region names exist (even if just 1).
     * Returns empty array if no same-region companies exist — caller
     * should skip dynamic expansion rather than pollute with wrong-region seeds.
     *
     * @return string[]
     */
    public function findNamesBySectorAndRegion(string $sector, string $region): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.name')
            ->where('c.sector = :sector')
            ->andWhere('c.region = :region')
            ->setParameter('sector', $sector)
            ->setParameter('region', strtoupper($region))
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'name');
    }

    /**
     * Get sector breakdown in a single query instead of N queries per sector.
     * Optimized for dashboard - eliminates 6+ separate queries.
     * 
     * @return array<string, int> Map of sector => company count
     */
    public function getCompanyCountsBySector(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.sector, COUNT(c.id) as cnt')
            ->where('c.sector IS NOT NULL')
            ->groupBy('c.sector')
            ->getQuery()
            ->getResult();
        
        $counts = [];
        foreach ($results as $row) {
            if ($row['sector']) {
                $counts[$row['sector']] = (int) $row['cnt'];
            }
        }
        
        return $counts;
    }

    /**
     * Get pipeline stage distribution in a single query instead of N queries per stage.
     * Optimized for dashboard - eliminates 6 separate queries.
     * 
     * @return array<string, int> Map of stage => company count
     */
    public function getCompanyCountsByPipelineStage(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.pipelineStage, COUNT(c.id) as cnt')
            ->where('c.pipelineStage IS NOT NULL')
            ->groupBy('c.pipelineStage')
            ->getQuery()
            ->getResult();
        
        $counts = [];
        foreach ($results as $row) {
            if ($row['pipelineStage']) {
                $counts[$row['pipelineStage']] = (int) $row['cnt'];
            }
        }
        
        return $counts;
    }
}
