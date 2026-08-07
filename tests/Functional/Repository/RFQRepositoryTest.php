<?php

namespace App\Tests\Functional\Repository;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Repository\RFQRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * RFQ statistics queries must return REAL counts. These methods previously
 * used literals that never matched any stored value ('Framework' vs
 * 'Framework Agreement', 'Award' vs 'Won') and always returned 0.
 */
class RFQRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RFQRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(RFQRepository::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->em->getConnection());
        $this->resetTables(['rfqs', 'companies']);
    }

    private function resetTables(array $tables): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function company(string $name): Company
    {
        $company = new Company();
        $company->setName($name);
        $company->setAccountTier(Company::TIER_B);
        $company->setSector('Automotive');
        return $company;
    }

    private function rfq(Company $company, string $type, string $status, string $date): RFQ
    {
        $rfq = new RFQ();
        $rfq->setCompany($company);
        $rfq->setRfqNumber('RFQ-' . uniqid());
        $rfq->setRfqDate(new \DateTime($date));
        $rfq->setType($type);
        $rfq->setStatus($status);
        return $rfq;
    }

    public function testCountNPIAwardsReturnsRealWonNpiCounts(): void
    {
        $company = $this->company('Acme');
        $this->em->persist($company);
        $windowStart = new \DateTime('2026-01-01');
        $windowEnd = new \DateTime('2026-12-31');

        $this->em->persist($this->rfq($company, RFQ::TYPE_NPI, RFQ::STATUS_WON, '2026-03-15'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_NPI, RFQ::STATUS_WON, '2026-06-01'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_NPI, RFQ::STATUS_LOST, '2026-04-01'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_NPI, RFQ::STATUS_WON, '2025-11-01')); // outside window
        $this->em->persist($this->rfq($company, RFQ::TYPE_FRAMEWORK, RFQ::STATUS_WON, '2026-05-01')); // wrong type
        $this->em->flush();

        $this->assertSame(2, $this->repository->countNPIAwards($windowStart, $windowEnd));
    }

    public function testCountFrameworkAgreementsReturnsRealWonFrameworkCounts(): void
    {
        $company = $this->company('Beta');
        $this->em->persist($company);
        $windowStart = new \DateTime('2026-01-01');
        $windowEnd = new \DateTime('2026-12-31');

        $this->em->persist($this->rfq($company, RFQ::TYPE_FRAMEWORK, RFQ::STATUS_WON, '2026-02-10'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_FRAMEWORK, RFQ::STATUS_LOST, '2026-03-10'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_FRAMEWORK, RFQ::STATUS_WON, '2026-04-10'));
        $this->em->persist($this->rfq($company, RFQ::TYPE_NPI, RFQ::STATUS_WON, '2026-05-10')); // wrong type
        $this->em->flush();

        $this->assertSame(2, $this->repository->countFrameworkAgreements($windowStart, $windowEnd));
    }

    public function testCountsReturnZeroWhenNoMatchingRows(): void
    {
        $windowStart = new \DateTime('2026-01-01');
        $windowEnd = new \DateTime('2026-12-31');

        $this->assertSame(0, $this->repository->countNPIAwards($windowStart, $windowEnd));
        $this->assertSame(0, $this->repository->countFrameworkAgreements($windowStart, $windowEnd));
    }
}
