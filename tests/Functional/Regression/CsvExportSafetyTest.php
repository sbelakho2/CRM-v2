<?php

namespace App\Tests\Functional\Regression;

use App\Entity\BomLine;
use App\Entity\Company;
use App\Entity\Lead;
use App\Entity\Quote;
use App\Service\CsvExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Critical-surface coverage: the three streamed CSV exports. Every export
 * is a data-exfiltration surface (they hand over lead PII and BOM pricing),
 * and every cell must pass the shared formula-injection sanitizer — a
 * lead whose company name starts with "=" becomes a spreadsheet formula on
 * the salesperson's machine.
 */
class CsvExportSafetyTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private CsvExportService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(CsvExportService::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['bom_lines', 'quotes', 'companies', 'leads'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function makeLead(string $companyName): Lead
    {
        $lead = new Lead();
        $lead->setCompanyName($companyName);
        $lead->setLeadUrl('https://example.com/lead');
        $lead->setLeadScore(42);
        $lead->setReviewStatus('approved');
        $this->em->persist($lead);

        return $lead;
    }

    public function testLeadExportStreamsAllDefaultFields(): void
    {
        $this->makeLead('=HYPERLINK("http://evil","click") Co');
        $this->em->flush();
        $this->em->clear();

        $csv = $this->capture($this->service->exportLeads());

        $this->assertStringContainsString('Company Name', $csv, 'header row must carry human labels');
        $this->assertStringContainsString('Review Status', $csv);
        // Formula-injection guard: the raw "=HYPERLINK" cell must never ship.
        $this->assertStringContainsString("\t=HYPERLINK", $csv, 'formula-leading company names must be tab-prefixed');
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv, 'unsanitized formula cell leaked');
    }

    public function testLeadExportFieldWhitelistDropsUnknownFields(): void
    {
        $this->makeLead('Whitelist Co');
        $this->em->flush();
        $this->em->clear();

        $csv = $this->capture($this->service->exportLeads([], ['id', 'company_name', 'DROP TABLE leads; --']));

        $lines = explode("\n", trim($csv));
        $this->assertSame('ID,"Company Name"', $lines[0], 'unknown field must be dropped, never echoed as a header');
        $this->assertStringNotContainsString('DROP TABLE', $csv);
    }

    public function testQuoteBomExportCarriesPricingAndTotals(): void
    {
        $company = new Company();
        $company->setName('BOM Export Co');
        $company->setAccountTier('B');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setQuoteNumber('QTE-CSV-1');
        $this->em->persist($quote);

        $line = new BomLine();
        $line->setQuote($quote);
        $line->setLineNumber(1);
        $line->setMpn('STM32F103');
        $line->setQuantity(250);
        $line->setUnitPrice('2.00');
        $line->setExtendedPrice('500.00');
        $this->em->persist($line);
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        $csv = $this->capture($this->service->exportQuoteBom($quote));

        $this->assertStringContainsString('STM32F103', $csv);
        $this->assertStringContainsString('500.00', $csv, 'extended price must appear');
        $this->assertStringContainsString('"Total Lines",1', $csv, 'summary section must reflect the real line count');
    }

    public function testSourcingReportCoversAllRequestedQuotes(): void
    {
        $company = new Company();
        $company->setName('Sourcing Co');
        $company->setAccountTier('C');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setQuoteNumber('QTE-SRC-1');
        $this->em->persist($quote);

        $line = new BomLine();
        $line->setQuote($quote);
        $line->setLineNumber(1);
        $line->setMpn('CAP-100N');
        $line->setQuantity(1000);
        $line->setUnitPrice('0.02');
        $this->em->persist($line);
        $this->em->flush();
        $this->em->clear();

        $csv = $this->capture($this->service->exportSourcingReport([$quote->getId()]));

        $this->assertStringContainsString('CAP-100N', $csv, 'each requested quote must contribute its lines');
        $this->assertStringContainsString('QTE-SRC-1', $csv);
    }

    public function testSourcingReportWithNoQuotesStillStreamsValidCsv(): void
    {
        // An empty selection streams the (empty-data) header row and
        // nothing else — no crash, no fabricated rows.
        $csv = $this->capture($this->service->exportSourcingReport([]));
        $this->assertIsString($csv);
        $this->assertStringContainsString('MPN', $csv);
        $this->assertSame(1, substr_count(trim($csv), "\n") + (trim($csv) !== '' ? 1 : 0), 'headers only — zero data rows');
    }
}
