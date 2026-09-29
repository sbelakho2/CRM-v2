<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Company;
use App\Entity\Quote;
use App\Entity\QuoteAcceptance;
use App\Entity\QuoteCustomerRequest;
use App\Service\PricingEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-7 P0 regressions: the public live-quote request/accept flows were
 * FALSE-SUCCESS — "submitted"/"accepted" responses with nothing durable
 * behind them, and live pricing used a second, divergent pricing engine.
 *
 * Contract now: a request/acceptance MUST survive in the database (clear the
 * ORM, reload, verify), and live pricing MUST equal canonical pricing for
 * the same BOM + quantity.
 */
class LiveQuoteDurabilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['quote_acceptances', 'quote_customer_requests', 'bom_lines', 'quotes', 'companies'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function csrfFor(Quote $quote): string
    {
        $crawler = $this->client->request('GET', '/quote/live/' . $quote->getPublicToken());
        preg_match("/csrfToken = '([^']+)'/", $crawler->html(), $m);

        return $m[1] ?? '';
    }

    private function makeQuoteWithBom(): Quote
    {
        $company = new Company();
        $company->setName('Live Quote Co');
        $company->setAccountTier('C');
        $company->setSector('Industrial');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setPublicToken('tok_' . bin2hex(random_bytes(12)));
        $quote->setTokenExpiresAt((new \DateTime())->modify('+30 days'));
        $quote->setInteractiveEnabled(true);
        $this->em->persist($quote);
        $this->em->flush();

        // BOM with unit prices so canonical pricing has real material cost.
        $line1 = new \App\Entity\BomLine();
        $line1->setQuote($quote);
        $line1->setLineNumber(1);
        $line1->setMpn('STM32F103');
        $line1->setQuantity(500);
        $line1->setUnitPrice('2.00');
        $this->em->persist($line1);
        $line2 = new \App\Entity\BomLine();
        $line2->setQuote($quote);
        $line2->setLineNumber(2);
        $line2->setMpn('CAP-100N');
        $line2->setQuantity(2500);
        $line2->setUnitPrice('0.02');
        $this->em->persist($line2);
        $this->em->flush();

        return $quote;
    }

    public function testRequestQuoteSurvivesInDatabase(): void
    {
        $quote = $this->makeQuoteWithBom();

        // Public POST endpoints are CSRF-protected (intentionally): fetch a
        // valid token from the rendered page first.
        $crawler = $this->client->request('GET', '/quote/live/' . $quote->getPublicToken());
        $csrf = $crawler->filter('script')->text();
        preg_match("/csrfToken = '([^']+)'/", $crawler->html(), $m);
        $csrfToken = $m[1] ?? '';
        $this->assertNotSame('', $csrfToken, 'Live quote page must expose the CSRF token');
        $response = $this->client->request(
            'POST',
            '/quote/live/' . $quote->getPublicToken() . '/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $csrfToken],
            (string) json_encode(['quantity' => 500, 'notes' => 'Need by Q3'])
        );
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('request_id', $body, 'The response must carry the durable request id');
        $requestId = $body['request_id'];

        // Clear the ORM and reload from the DATABASE — the contract.
        $this->em->clear();
        $saved = $this->em->find(QuoteCustomerRequest::class, $requestId);
        $this->assertNotNull($saved, 'The request must survive in the database');
        $this->assertSame(500, $saved->getRequestedQuantity());
        $this->assertSame('Need by Q3', $saved->getCustomerNotes());
        $this->assertSame(QuoteCustomerRequest::STATUS_NEW, $saved->getStatus());
        $this->assertNotNull($saved->getQuote());
        $this->assertSame($quote->getId(), $saved->getQuote()->getId());
        $this->assertNotNull($saved->getTokenFingerprint(), 'Token provenance (fingerprint) recorded');
    }

    public function testAcceptancePersistsIdentityPricingAndPoNumber(): void
    {
        $quote = $this->makeQuoteWithBom();

        $this->client->request(
            'POST',
            '/quote/live/' . $quote->getPublicToken() . '/accept',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $this->csrfFor($quote)],
            (string) json_encode([
                'quantity' => 250,
                'name' => 'Jane Buyer',
                'email' => 'jane@buyer.example',
                'company' => 'Buyer Corp',
                'po_number' => 'PO-2024-777',
            ])
        );
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('acceptance_id', $body, 'Response must reference the durable acceptance');
        $this->assertArrayHasKey('accepted_total', $body);
        $acceptanceId = $body['acceptance_id'];

        $this->em->clear();
        $saved = $this->em->find(QuoteAcceptance::class, $acceptanceId);
        $this->assertNotNull($saved, 'The acceptance must survive in the database');
        $this->assertSame(250, $saved->getAcceptedQuantity());
        $this->assertSame('Jane Buyer', $saved->getCustomerName());
        $this->assertSame('PO-2024-777', $saved->getPoNumber(), 'PO number is business data, not a log line');
        $this->assertNotNull($saved->getPricingSnapshot(), 'Accepted pricing snapshot is immutable evidence');
        $this->assertGreaterThan(0, (float) $saved->getAcceptedTotal());

        $this->em->clear();
        $reloadedQuote = $this->em->find(Quote::class, $quote->getId());
        $this->assertSame('accepted', $reloadedQuote->getStatus());
        $this->assertSame(250, $reloadedQuote->getQuantity());
    }

    public function testAcceptanceRejectsNonPositiveQuantity(): void
    {
        $quote = $this->makeQuoteWithBom();

        $this->client->request(
            'POST',
            '/quote/live/' . $quote->getPublicToken() . '/accept',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $this->csrfFor($quote)],
            (string) json_encode(['quantity' => 0, 'name' => 'X'])
        );
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quote_acceptances'));
    }

    public function testLivePricingMatchesCanonicalTotals(): void
    {
        $quote = $this->makeQuoteWithBom();

        // Same processed-line shape the copilot pipeline produces.
        $processedLines = [
            ['mpn' => 'STM32F103', 'quantity' => 500, 'unit_price' => 2.0, 'extended_price' => 1000.0],
            ['mpn' => 'CAP-100N', 'quantity' => 2500, 'unit_price' => 0.02, 'extended_price' => 50.0],
        ];

        // CANONICAL: PricingEngine totals (what QuoteCoPilot sets on the quote).
        $engine = static::getContainer()->get(PricingEngine::class);
        $canonical = $engine->calculateQuoteTotals($processedLines, 25.0, 'USD');

        // Golden assertion: the canonical engine's margin model is the one the
        // customer sees. 1050 * 1.25 = 1312.50 — the live tier MUST agree.
        $this->assertSame(1312.50, $canonical['total']);

        // Live service priced with the canonical engine returns the same total.
        $service = static::getContainer()->get(\App\Service\InteractiveLiveQuoteService::class);
        $method = new \ReflectionMethod($service, 'calculatePricingForQuantity');
        $bomData = [
            ['mpn' => 'STM32F103', 'quantity_per_unit' => 500, 'pricing' => [['quantity' => 1, 'price' => 2.0]], 'stock' => 9999],
            ['mpn' => 'CAP-100N', 'quantity_per_unit' => 2500, 'pricing' => [['quantity' => 1, 'price' => 0.02]], 'stock' => 9999],
        ];
        $live = $method->invoke($service, $bomData, 1, 'USD');

        $this->assertSame($canonical['total'], $live['canonical_total'], 'Live pricing MUST equal canonical quote totals');
        $this->assertSame(25.0, $live['margin_percent']);
    }
}
