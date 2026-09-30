<?php

namespace App\Tests\Functional\Regression;

use App\Entity\ReportDefinition;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-8 UI-1: the Report Builder screen LOOKED like a working drag-and-drop
 * designer, but its generated form structure did not agree with
 * ReportController::builder() — flat columns[] strings vs {field,aggregation}
 * records, filters[fieldName][operator] maps vs iterable filter records,
 * sorting[field] vs orderBy, gt/lt vs greater_than/less_than. The visual
 * builder could save configurations the engine then executed incorrectly or
 * not at all.
 *
 * This test POSTs the EXACT payload the rebuilt template emits and asserts
 * the saved definition round-trips through execution.
 */
class ReportBuilderPayloadContractTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['report_definitions', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $user = new User();
        $user->setEmail('builder-r8@example.com');
        $user->setFirstName('Buil');
        $user->setLastName('Der');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        $user->setActive(true);
        $this->em->persist($user);

        $report = new ReportDefinition();
        $report->setName('Payload Contract Report');
        $report->setDataSource('company');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setCreatedBy($user);
        $this->em->persist($report);
        $this->em->flush();

        $this->reportId = $report->getId();
        $this->userId = $user->getId();
    }

    private int $reportId;
    private int $userId;

    public function testTemplatePayloadShapeRoundTripsThroughTheEngine(): void
    {
        $this->client->loginUser($this->em->find(User::class, $this->userId));

        $crawler = $this->client->request('GET', '/reports/' . $this->reportId . '/builder');
        $this->assertResponseIsSuccessful();
        // The rebuilt page renders explicit per-field actions (click-first UX,
        // keyboard equivalent to drag-and-drop).
        $this->assertSelectorExists('[data-add="column"]');
        $this->assertSelectorExists('[data-add="filter"]');
        $this->assertSelectorExists('[data-add="sort"]');
        $this->assertSelectorExists('#field-search[type="search"]');

        $token = $crawler->filter('#builder-form input[name="_token"]')->attr('value');

        // THE EXACT field names the rebuilt template emits:
        $this->client->request('POST', '/reports/' . $this->reportId . '/builder', [
            '_token' => $token,
            'columns' => [
                ['field' => 'name', 'aggregation' => ''],
                ['field' => 'sector', 'aggregation' => ''],
                ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
            ],
            'filters' => [
                ['field' => 'sector', 'operator' => 'equals', 'value' => 'Automotive'],
                ['field' => 'createdAt', 'operator' => 'greater_or_equal', 'value' => '2020-01-01'],
            ],
            'orderBy' => [
                ['field' => 'createdAt', 'direction' => 'DESC'],
            ],
            'dateField' => 'createdAt',
            'dateRangePreset' => 'this_year',
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $report = $this->em->find(ReportDefinition::class, $this->reportId);

        // Columns saved as structured records (not flat strings).
        $columns = $report->getColumns();
        $this->assertCount(3, $columns);
        $this->assertSame('name', $columns[0]['field']);
        $this->assertSame('count', $columns[2]['aggregation']);
        $this->assertSame('total', $columns[2]['alias']);

        // Filters saved as records with canonical operator names.
        $filters = $report->getFilters();
        $this->assertCount(2, $filters);
        $this->assertSame('greater_or_equal', $filters[1]['operator']);

        // Order-by saved under orderBy with uppercase direction.
        $orderBy = $report->getOrderBy();
        $this->assertCount(1, $orderBy);
        $this->assertSame('DESC', $orderBy[0]['direction']);

        // Date anchor validated against the whitelist.
        $this->assertSame('createdAt', $report->getDateField());

        // And the whole thing EXECUTES through the engine.
        $builder = static::getContainer()->get(\App\Service\ReportBuilderService::class);
        $results = $builder->executeReport($report);
        $this->assertTrue($results['success'], 'saved configuration must execute: ' . ($results['error'] ?? json_encode($results['meta']['warnings'] ?? [])));
        $this->assertArrayHasKey('name', $results['data'][0] ?? ['name' => null]);
    }

    public function testInvalidPayloadRowsAreDroppedNotStored(): void
    {
        $this->client->loginUser($this->em->find(User::class, $this->userId));

        $crawler = $this->client->request('GET', '/reports/' . $this->reportId . '/builder');
        $token = $crawler->filter('#builder-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/reports/' . $this->reportId . '/builder', [
            '_token' => $token,
            'columns' => [
                ['field' => 'industry', 'aggregation' => ''],   // phantom field
                ['field' => 'name', 'aggregation' => ''],       // valid
            ],
            'filters' => [
                ['field' => 'status', 'operator' => 'equals', 'value' => 'x'], // phantom field
            ],
            'orderBy' => [
                ['field' => 'size', 'direction' => 'DESC'], // phantom field
            ],
        ]);

        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $report = $this->em->find(ReportDefinition::class, $this->reportId);

        $this->assertCount(1, $report->getColumns(), 'phantom column dropped at save time');
        $this->assertSame('name', $report->getColumns()[0]['field']);
        $this->assertCount(0, $report->getFilters(), 'phantom filter dropped at save time');
        $this->assertCount(0, $report->getOrderBy(), 'phantom sort dropped at save time');
    }
}
