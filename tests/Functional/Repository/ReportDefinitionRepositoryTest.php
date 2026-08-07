<?php

namespace App\Tests\Functional\Repository;

use App\Entity\ReportDefinition;
use App\Entity\User;
use App\Repository\ReportDefinitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * getStatistics() must ACCUMULATE across grouping rows (it previously
 * overwrote totals per group, under-reporting counts).
 */
class ReportDefinitionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReportDefinitionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(ReportDefinitionRepository::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->em->getConnection());
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['report_definitions', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function user(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Test');
        $user->setLastName('User');
        return $user;
    }

    private function report(User $owner, string $name, string $type, string $source, bool $favorite): ReportDefinition
    {
        $report = new ReportDefinition();
        $report->setName($name);
        $report->setReportType($type);
        $report->setDataSource($source);
        $report->setColumns(['a']);
        $report->setFilters([]);
        $report->setGroupBy([]);
        $report->setOrderBy([]);
        $report->setCreatedBy($owner);
        $report->setIsFavorite($favorite);
        return $report;
    }

    public function testGetStatisticsAccumulatesAcrossGroups(): void
    {
        $owner = $this->user('owner@example.com');
        $this->em->persist($owner);

        // Two different (dataSource, reportType) groups — both must count
        $this->em->persist($this->report($owner, 'R1', ReportDefinition::TYPE_TABLE, 'customers', true));
        $this->em->persist($this->report($owner, 'R2', ReportDefinition::TYPE_CHART_BAR, 'customers', false));
        $this->em->persist($this->report($owner, 'R3', ReportDefinition::TYPE_TABLE, 'quotes', false));
        $this->em->flush();

        $stats = $this->repository->getStatistics($owner);

        $this->assertSame(3, $stats['total'], 'All rows across groups must be counted');
        $this->assertSame(3, $stats['myReports'], 'All reports are owned by the user');
        $this->assertSame(1, $stats['favorites']);
        $this->assertSame(2, $stats['bySource']['customers']);
        $this->assertSame(1, $stats['bySource']['quotes']);
        $this->assertSame(2, $stats['byType'][ReportDefinition::TYPE_TABLE]);
        $this->assertSame(1, $stats['byType'][ReportDefinition::TYPE_CHART_BAR]);
    }

    public function testGetStatisticsExcludesOtherUsersReportsUnlessPublic(): void
    {
        $owner = $this->user('owner@example.com');
        $other = $this->user('other@example.com');
        $this->em->persist($owner);
        $this->em->persist($other);

        $this->em->persist($this->report($owner, 'Mine', ReportDefinition::TYPE_TABLE, 'customers', false));
        $this->em->persist($this->report($other, 'Theirs', ReportDefinition::TYPE_TABLE, 'quotes', false));
        $this->em->flush();

        $stats = $this->repository->getStatistics($owner);

        $this->assertSame(1, $stats['total']);
        $this->assertSame(1, $stats['myReports']);
    }

    public function testGetStatisticsReturnsZerosWhenEmpty(): void
    {
        $owner = $this->user('owner@example.com');
        $this->em->persist($owner);
        $this->em->flush();

        $stats = $this->repository->getStatistics($owner);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['myReports']);
        $this->assertSame(0, $stats['favorites']);
        $this->assertSame([], $stats['bySource']);
        $this->assertSame([], $stats['byType']);
    }
}
