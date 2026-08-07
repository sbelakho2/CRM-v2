<?php

namespace App\Tests\Functional\Repository;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The silent setMaxResults(500) caps were removed from data-completeness
 * paths. findAllWebsiteDomains() must return every domain regardless of
 * volume (dedup integrity for the webcrawler), and findWithDetails() must
 * eager-load relations in a single query.
 */
class CompanyRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CompanyRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(CompanyRepository::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->em->getConnection());
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['companies', 'contacts', 'activities', 'rfqs'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function company(string $name, ?string $website = null): Company
    {
        $company = new Company();
        $company->setName($name);
        $company->setAccountTier(Company::TIER_B);
        $company->setSector('Automotive');
        if ($website) {
            $company->setWebsite($website);
        }
        return $company;
    }

    public function testFindAllWebsiteDomainsReturnsMoreThanFiveHundredDomains(): void
    {
        // Seed 501 companies with websites — the removed cap used to stop at 500
        for ($i = 0; $i < 501; $i++) {
            $this->em->persist($this->company('Company ' . $i, sprintf('https://company-%03d.example.com', $i)));
        }
        // Plus some without websites (must be excluded, not counted)
        $this->em->persist($this->company('No Website Co'));
        $this->em->flush();

        $domains = $this->repository->findAllWebsiteDomains();

        $this->assertGreaterThan(500, count($domains), 'Domains beyond 500 must not be truncated');
        $this->assertSame(501, count($domains));
        $this->assertContains('company-000.example.com', $domains);
        $this->assertContains('company-500.example.com', $domains);
        $this->assertSame($domains, array_values(array_unique($domains)), 'Domains must be unique');
    }

    public function testFindWithDetailsEagerLoadsRelations(): void
    {
        $company = $this->company('Eager Co', 'https://eager.example.com');
        $this->em->persist($company);
        $this->em->flush();

        // Clear the identity map so everything must come from the query
        $this->em->clear();

        $loaded = $this->repository->findWithDetails($company->getId());

        $this->assertNotNull($loaded);
        $this->assertSame('Eager Co', $loaded->getName());
        $this->assertTrue($loaded->getContacts()->isInitialized(), 'contacts must be eager-loaded');
        $this->assertTrue($loaded->getActivities()->isInitialized(), 'activities must be eager-loaded');
        $this->assertTrue($loaded->getRfqs()->isInitialized(), 'rfqs must be eager-loaded');
    }

    public function testFindWithDetailsReturnsNullForMissingCompany(): void
    {
        $this->assertNull($this->repository->findWithDetails(999999));
    }
}
