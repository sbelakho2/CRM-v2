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
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->andWhere('c.archivedAt IS NULL')
            ->andWhere('c.createdAt >= :start')
            ->andWhere('c.createdAt <= :end')
            ->setParameter('stage', $stage)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * @return list<Company>
     */
    public function findBySectorAndTier(string $sector, string $tier): array
    {
        /** @var list<Company> $result */
        $result = $this->createQueryBuilder('c')
            ->where('c.sector = :sector')
            ->andWhere('c.accountTier = :tier')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('sector', $sector)
            ->setParameter('tier', $tier)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    public function countBySector(string $sector): int
    {
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.sector = :sector')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('sector', $sector)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function countByPipelineStage(string $stage): int
    {
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('stage', $stage)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Return all existing company website domains as a flat array.
     *
     * Used by GoogleDorkService to inject -site: exclusions into
     * search queries so that previously-found companies are pushed
     * out of search results, making room for new discoveries.
     *
     * NOTE: intentionally INCLUDES archived companies — an archived company
     * must stay excluded from future discovery results.
     *
     * @return list<string> Root domains (e.g. ['ezz-elarab.com', 'aboulfotouh-egypt.com'])
     */
    public function findAllWebsiteDomains(): array
    {
        /** @var list<array{website: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.website')
            ->where('c.website IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        $domains = [];
        foreach ($rows as $row) {
            $url = is_string($row['website']) ? $row['website'] : '';
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = preg_replace('/^www\./', '', strtolower($host));
                if ($domain !== null && $domain !== '') {
                    $domains[] = $domain;
                }
            }
        }
        return array_values(array_unique($domains));
    }

    /**
     * Return all company names for a given sector, for dynamic query expansion.
     *
     * NOTE: intentionally INCLUDES archived companies (discovery exclusion
     * seeds — archived rows must still suppress re-discovery).
     *
     * @return list<string>
     */
    public function findNamesBySector(string $sector): array
    {
        /** @var list<array{name: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.name')
            ->where('c.sector = :sector')
            ->setParameter('sector', $sector)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn ($row) => is_string($row['name']) ? $row['name'] : '',
            $rows
        );
    }

    /**
     * Return company names for a given sector AND region.
     * Returns whatever same-region names exist (even if just 1).
     * Returns empty array if no same-region companies exist — caller
     * should skip dynamic expansion rather than pollute with wrong-region seeds.
     *
     * NOTE: intentionally INCLUDES archived companies (discovery exclusion
     * seeds — archived rows must still suppress re-discovery).
     *
     * @return list<string>
     */
    public function findNamesBySectorAndRegion(string $sector, string $region): array
    {
        /** @var list<array{name: mixed}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.name')
            ->where('c.sector = :sector')
            ->andWhere('c.region = :region')
            ->setParameter('sector', $sector)
            ->setParameter('region', strtoupper($region))
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn ($row) => is_string($row['name']) ? $row['name'] : '',
            $rows
        );
    }

    /**
     * Get a company with its contacts, activities and RFQs eagerly loaded.
     *
     * The company detail page renders all three collections, so a single
     * JOIN FETCH query replaces three lazy-load queries (N+1).
     */
    public function findWithDetails(int $id): ?Company
    {
        /** @var Company|null $result */
        $result = $this->createQueryBuilder('c')
            ->leftJoin('c.contacts', 'contacts')
            ->addSelect('contacts')
            ->leftJoin('c.activities', 'activities')
            ->addSelect('activities')
            ->leftJoin('c.rfqs', 'rfqs')
            ->addSelect('rfqs')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            // Deterministic collection order: without ORDER BY the SQL engine
            // picks its own row order, so "recent activity" panels sliced the
            // collection in an arbitrary (often oldest/random) order and the
            // newest activities never surfaced on the company page.
            ->orderBy('activities.activityDate', 'DESC')
            ->addOrderBy('rfqs.createdAt', 'DESC')
            ->addOrderBy('contacts.firstName', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Get sector breakdown in a single query instead of N queries per sector.
     * Optimized for dashboard - eliminates 6+ separate queries.
     * 
     * @return array<string, int> Map of sector => company count
     */
    public function getCompanyCountsBySector(): array
    {
        /** @var list<array{sector: mixed, cnt: mixed}> $results */
        $results = $this->createQueryBuilder('c')
            ->select('c.sector, COUNT(c.id) as cnt')
            ->where('c.sector IS NOT NULL')
            ->andWhere('c.archivedAt IS NULL')
            ->groupBy('c.sector')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            if (is_string($row['sector']) && $row['sector'] !== '' && is_numeric($row['cnt'])) {
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
        /** @var list<array{pipelineStage: mixed, cnt: mixed}> $results */
        $results = $this->createQueryBuilder('c')
            ->select('c.pipelineStage, COUNT(c.id) as cnt')
            ->where('c.pipelineStage IS NOT NULL')
            ->andWhere('c.archivedAt IS NULL')
            ->groupBy('c.pipelineStage')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            if (is_string($row['pipelineStage']) && $row['pipelineStage'] !== '' && is_numeric($row['cnt'])) {
                $counts[$row['pipelineStage']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }
}
