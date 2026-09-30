<?php

namespace App\Tests\Functional\Regression;

use App\Entity\ReportDefinition;
use App\Service\CsvExportService;
use App\Service\ReportBuilderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-8 systemic gate: NO hand-maintained reporting map may drift from the
 * real Doctrine schema again. Every SOURCE_FIELDS entry, every suggested
 * report, and every date-range anchor must resolve through actual Doctrine
 * metadata AND execute against MySQL. This is the class of test that would
 * have caught both the phantom-field reports (round 7) and the
 * isPrimaryContact getter-name bug (round 8) at test time.
 */
class ReportBuilderSchemaTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private ReportBuilderService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = new ReportBuilderService(
            $this->em,
            self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class),
        );
    }

    public static function dataSourceProvider(): array
    {
        return array_map(fn ($s) => [$s], [
            'company', 'contact', 'lead', 'rfq', 'quote', 'task', 'calendar', 'email_campaign', 'email_send',
        ]);
    }

    private function entityClassFor(string $source): string
    {
        $map = [
            'company' => \App\Entity\Company::class,
            'contact' => \App\Entity\Contact::class,
            'lead' => \App\Entity\Lead::class,
            'rfq' => \App\Entity\RFQ::class,
            'quote' => \App\Entity\Quote::class,
            'task' => \App\Entity\Task::class,
            'calendar' => \App\Entity\CalendarEvent::class,
            'email_campaign' => \App\Entity\EmailCampaign::class,
            'email_send' => \App\Entity\EmailSend::class,
        ];

        return $map[$source];
    }

    /**
     * Walk a "relation.field" or "field" path through Doctrine metadata —
     * throws when any hop does not exist (exactly what DQL does at runtime).
     */
    private function assertFieldResolves(string $source, string $field): void
    {
        $class = $this->entityClassFor($source);

        if (!str_contains($field, '.')) {
            $metadata = $this->em->getClassMetadata($class);
            $this->assertTrue(
                $metadata->hasField($field) || $metadata->hasAssociation($field),
                "{$source}.{$field} is not a mapped field or association"
            );

            return;
        }

        [$relation, $relField] = explode('.', $field, 2);
        $metadata = $this->em->getClassMetadata($class);
        $this->assertTrue(
            $metadata->hasAssociation($relation),
            "{$source}.{$relation} is not a mapped association (field {$field})"
        );
        $targetClass = $metadata->getAssociationTargetClass($relation);
        $targetMetadata = $this->em->getClassMetadata($targetClass);
        $this->assertTrue(
            $targetMetadata->hasField($relField) || $targetMetadata->hasAssociation($relField),
            "{$targetClass}::{$relField} is not mapped (report field {$field})"
        );
    }

    /** @dataProvider dataSourceProvider */
    public function testEverySourceFieldResolvesThroughDoctrineMetadata(string $source): void
    {
        $fields = $this->service->getFieldsForSource($source);
        $this->assertNotEmpty($fields, "source {$source} must declare fields");

        foreach ($fields as $field => $def) {
            $this->assertFieldResolves($source, $field);
        }
    }

    /** @dataProvider dataSourceProvider */
    public function testEveryFieldExecutesAgainstMysql(string $source): void
    {
        $fields = $this->service->getFieldsForSource($source);
        $columns = [];
        foreach (array_keys($fields) as $i => $field) {
            $columns[] = ['field' => $field, 'alias' => 'c' . $i];
        }

        $report = new ReportDefinition();
        $report->setDataSource($source);
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setColumns($columns);

        $results = $this->service->executeReport($report);

        $this->assertTrue(
            $results['success'],
            "SELECT of every {$source} field failed: " . ($results['error'] ?? '?') . ' warnings: ' . json_encode($results['meta']['warnings'] ?? [])
        );
    }

    /** @dataProvider dataSourceProvider */
    public function testEverySuggestedReportExecutesAgainstMysql(string $source): void
    {
        $suggestions = $this->service->getSuggestedReports($source);
        $this->assertNotEmpty($suggestions, "source {$source} must have executable suggestions");

        foreach ($suggestions as $suggestion) {
            $report = new ReportDefinition();
            $report->setDataSource($source);
            $report->setReportType($suggestion['type']);
            $report->setColumns($suggestion['columns'] ?? [['field' => 'id']]);
            if (!empty($suggestion['groupBy'])) {
                $report->setGroupBy($suggestion['groupBy']);
            }
            if (!empty($suggestion['dateField'])) {
                $report->setDateField($suggestion['dateField']);
                $report->setDateRangePreset(ReportDefinition::RANGE_THIS_YEAR);
            }

            $results = $this->service->executeReport($report);
            $this->assertTrue(
                $results['success'],
                "Suggested report '{$suggestion['name']}' ({$source}) fails to execute: " . ($results['error'] ?? '?')
                    . ' warnings: ' . json_encode($results['meta']['warnings'] ?? [])
            );
        }
    }

    /** @dataProvider dataSourceProvider */
    public function testEveryDateFieldIsValidDateAnchorAndExecutes(string $source): void
    {
        $dateFields = array_keys(array_filter(
            $this->service->getFieldsForSource($source),
            fn ($def) => in_array($def['type'] ?? '', ['datetime', 'date'], true)
        ));

        // Date-range anchors must ONLY be date/datetime fields.
        $nonDate = array_keys(array_filter(
            $this->service->getFieldsForSource($source),
            fn ($def) => !in_array($def['type'] ?? '', ['datetime', 'date'], true)
        ));
        foreach ($nonDate as $field) {
            $this->assertFalse($this->service->isValidDateField($field, $source), "{$source}.{$field} must not be a date anchor");
        }

        foreach ($dateFields as $field) {
            $this->assertTrue($this->service->isValidDateField($field, $source));

            $report = new ReportDefinition();
            $report->setDataSource($source);
            $report->setReportType(ReportDefinition::TYPE_TABLE);
            $report->setColumns([['field' => 'id', 'alias' => 'id']]);
            $report->setDateField($field);
            $report->setDateRangePreset(ReportDefinition::RANGE_THIS_YEAR);

            $results = $this->service->executeReport($report);
            $this->assertTrue($results['success'], "date range on {$source}.{$field} failed: " . ($results['error'] ?? '?'));
        }
    }

    public function testHostileAliasNeverReachesDql(): void
    {
        $report = new ReportDefinition();
        $report->setDataSource('company');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setColumns([
            ['field' => 'name', 'alias' => 'evil FROM App\Entity\User, (SELECT 1) AS x'],
        ]);

        $results = $this->service->executeReport($report);

        $this->assertTrue($results['success'], 'invalid alias must fall back to a generated one, never break DQL');
        foreach (array_keys($results['data'][0] ?? ['x' => 1]) as $key) {
            $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
        }
    }

    public function testHostileOrderByDirectionIsNormalized(): void
    {
        $report = new ReportDefinition();
        $report->setDataSource('company');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setColumns([['field' => 'id', 'alias' => 'id']]);
        $report->setOrderBy([
            ['field' => 'id', 'direction' => 'DESC; DROP TABLE companies; --'],
        ]);

        $results = $this->service->executeReport($report);
        $this->assertTrue($results['success'], 'hostile direction must be normalized to ASC, never interpolated');
    }

    public function testFilterWithHostileFieldNameIsSkipped(): void
    {
        $report = new ReportDefinition();
        $report->setDataSource('company');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setColumns([['field' => 'id', 'alias' => 'id']]);
        $report->setFilters([
            ['field' => 'id; DROP TABLE companies', 'operator' => 'equals', 'value' => 1],
            ['field' => 'name', 'operator' => 'contains', 'value' => 'zzz-no-match'],
        ]);

        $results = $this->service->executeReport($report);
        $this->assertTrue($results['success'], 'hostile filter field must be skipped, not interpolated');
    }

    public function testCsvExportNeutralizesFormulaInjection(): void
    {
        // One archived-free company row with a formula-leading name.
        $company = new \App\Entity\Company();
        $company->setName('=HYPERLINK("http://evil","click")');
        $company->setAccountTier('C');
        $this->em->persist($company);
        $this->em->flush();

        $report = new ReportDefinition();
        $report->setDataSource('company');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setColumns([['field' => 'name', 'alias' => 'name']]);

        $results = $this->service->executeReport($report);
        $csv = $this->service->exportToCsv($results['data'], $report);

        $this->assertStringContainsString("\t=HYPERLINK", $csv, 'formula-leading cell must be tab-prefixed');

        // cleanup
        $this->em->remove($company);
        $this->em->flush();
    }

    public function testCanonicalSourceCheckRejectsUnknownSources(): void
    {
        $this->assertTrue($this->service->supportsDataSource('company'));
        $this->assertTrue($this->service->supportsDataSource('email_send'));
        $this->assertFalse($this->service->supportsDataSource('companies'), 'plural alias must not resolve');
        $this->assertFalse($this->service->supportsDataSource('activities'), 'activities was never a source');
        $this->assertFalse($this->service->supportsDataSource(''));
    }
}
