<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Notification;
use App\Entity\Quote;
use App\Entity\QuoteAcceptance;
use App\Entity\QuoteCustomerRequest;
use App\Entity\User;
use App\Service\InteractiveLiveQuoteService;
use App\Service\PricingEngine;
use App\Service\RateLimitExceeded;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-8 live-quote contracts:
 * - Tier pricing reprices against the QUOTED price-break snapshot
 *   (99 units → $2.00, 100 → $1.80, 1000 → $1.55), and every live tier
 *   total equals the canonical engine's total for the same lines.
 * - Stock is only claimed when the sourcing snapshot carries a number.
 * - Acceptance is concurrency-idempotent: quote_acceptances.quote_id is
 *   UNIQUE at the database level and the race loser replays the winner.
 * - Zero-priced quotes fail closed (no priced lines → cannot accept).
 * - Configuration inputs are bounded (expiration 1..90, tiers normalized).
 * - Customer requests are rate-limited and surface into the sales workflow
 *   (notification + queue), and acceptances generate the follow-on event.
 */
class LiveQuotePricingBreaksTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private InteractiveLiveQuoteService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = new InteractiveLiveQuoteService(
            $this->em,
            $this->em->getRepository(Quote::class),
            new \Psr\Log\NullLogger(),
            self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class),
            self::getContainer()->get(PricingEngine::class),
        );

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['activities', 'notification', 'quote_acceptances', 'quote_customer_requests', 'bom_lines', 'quotes', 'companies', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function makeQuoteWithBreaks(): Quote
    {
        $company = new Company();
        $company->setName('Break Co');
        $company->setAccountTier('B');
        $company->setSector('Industrial');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setQuoteNumber('QTE-R8-' . bin2hex(random_bytes(4)));
        $quote->setPublicToken('tok_' . bin2hex(random_bytes(12)));
        $quote->setTokenExpiresAt((new \DateTime())->modify('+30 days'));
        $quote->setInteractiveEnabled(true);
        $quote->setQuantityOptions([99, 100, 500, 1000]);
        $this->em->persist($quote);

        // One line: quoted snapshot has REAL breaks from the sourcing run.
        $line = new \App\Entity\BomLine();
        $line->setQuote($quote);
        $line->setLineNumber(1);
        $line->setMpn('STM32F103');
        $line->setQuantity(1); // qty-per-unit: the tier quantity IS the line quantity
        $line->setUnitPrice('1.80');
        $line->setSourcingData([
            'source' => 'MOUSER',
            'stock' => 5000,
            'price_breaks' => [
                ['quantity' => 1, 'price' => 2.00],
                ['quantity' => 100, 'price' => 1.80],
                ['quantity' => 1000, 'price' => 1.55],
            ],
        ]);
        $this->em->persist($line);
        $this->em->flush();
        $this->em->clear();

        return $this->em->find(Quote::class, $quote->getId());
    }

    private function tierFor(Quote $quote, int $quantity): array
    {
        foreach ($this->service->calculateTierPricing($quote)['tiers'] as $tier) {
            if ($tier['quantity'] === $quantity) {
                return $tier;
            }
        }

        $this->fail("tier {$quantity} not found");
    }

    public function testTierPricingRepricesAgainstStoredBreaks(): void
    {
        $quote = $this->makeQuoteWithBreaks();

        $tier = $this->tierFor($quote, 99);
        $this->assertSame(2.00, $tier['per_unit_breakdown'][0]['unit_price'], '99 units must use the qty-1 break ($2.00)');

        $tier = $this->tierFor($quote, 100);
        $this->assertSame(1.80, $tier['per_unit_breakdown'][0]['unit_price'], '100 units must use the 100-break ($1.80)');

        $tier = $this->tierFor($quote, 1000);
        $this->assertSame(1.55, $tier['per_unit_breakdown'][0]['unit_price'], '1000 units must use the 1000-break ($1.55)');
    }

    public function testLiveTierTotalEqualsCanonicalTotal(): void
    {
        $quote = $this->makeQuoteWithBreaks();
        $engine = self::getContainer()->get(PricingEngine::class);

        foreach ([99, 100, 1000] as $qty) {
            $unitPrice = $this->tierFor($quote, $qty)['per_unit_breakdown'][0]['unit_price'];
            $extended = $unitPrice * $qty;

            $canonical = $engine->calculateQuoteTotals([[
                'mpn' => 'STM32F103',
                'description' => null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'extended_price' => $extended,
            ]], 25.0, 'USD');

            $this->assertSame(
                round($canonical['total'], 2),
                $this->tierFor($quote, $qty)['extended_price'],
                "live tier total at qty {$qty} must equal the canonical engine total"
            );
        }
    }

    public function testUnknownStockIsNotClaimedAsPartial(): void
    {
        $company = new Company();
        $company->setName('NoStock Co');
        $company->setAccountTier('C');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setQuoteNumber('QTE-NOSTOCK-' . bin2hex(random_bytes(3)));
        $this->em->persist($quote);

        $line = new \App\Entity\BomLine();
        $line->setQuote($quote);
        $line->setLineNumber(1);
        $line->setMpn('CAP-1U');
        $line->setQuantity(10);
        $line->setUnitPrice('0.50');
        // NO sourcing_data → no stock claim possible.
        $this->em->persist($line);
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        $tiers = $this->service->calculateTierPricing($quote);
        $this->assertNotSame('partial_stock', $tiers['tiers'][0]['stock_status'], 'fabricated stock=0 must not claim stock shortage');
    }

    public function testConfigurationInputsAreBounded(): void
    {
        $quote = $this->makeQuoteWithBreaks();

        // Negative expiration clamps to 1 day — never an instantly-expired token.
        $result = $this->service->enableInteractiveMode($quote, null, -30);
        $expires = new \DateTime($result['expires_at']);
        $this->assertGreaterThan(new \DateTime(), $expires);

        // Garbage tiers fall back to defaults; numeric strings survive.
        $result = $this->service->enableInteractiveMode($quote, ['100', 'abc', 0, -5, 250.0, 10000000000, 100], 30);
        $tiers = $result['quantity_tiers'];
        $this->assertContains(100, $tiers);
        $this->assertContains(250, $tiers);
        $this->assertNotContains(10000000000, $tiers);
        $sorted = $tiers;
        sort($sorted);
        $this->assertSame($sorted, $tiers, 'tiers must be normalized ascending');
        $this->assertSame($tiers, array_unique($tiers), 'tiers must be unique');
    }

    public function testZeroPricedQuoteFailsClosedOnAcceptance(): void
    {
        $company = new Company();
        $company->setName('Zero Co');
        $company->setAccountTier('C');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setCurrency('USD');
        $quote->setQuoteNumber('QTE-ZERO-' . bin2hex(random_bytes(3)));
        $quote->setPublicToken('tok_zero_' . bin2hex(random_bytes(8)));
        $quote->setTokenExpiresAt((new \DateTime())->modify('+1 day'));
        $this->em->persist($quote);

        // No BOM lines at all → no pricing → cannot accept.
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        try {
            $this->service->acceptQuote($quote, 100, ['name' => 'T'], 'tok_zero_' === substr((string) $quote->getPublicToken(), 0, 9) ? $quote->getPublicToken() : $quote->getPublicToken());
            $this->fail('zero-priced acceptance must fail closed');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsStringIgnoringCase('cannot be accepted', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM quote_acceptances'));
    }

    public function testArchivedQuoteRejectsAcceptanceAndMutations(): void
    {
        $quote = $this->makeQuoteWithBreaks();
        $archiver = new User();
        $archiver->setEmail('archiver-r8@example.com');
        $archiver->setFirstName('Arc');
        $archiver->setLastName('Hiver');
        $archiver->setPassword('x');
        $archiver->setRoles(['ROLE_ADMIN']);
        $archiver->setActive(true);
        $this->em->persist($archiver);
        $this->em->flush();
        $quote->archive($archiver);
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->acceptQuote($quote, 100, [], $quote->getPublicToken());
    }

    public function testAcceptanceUniqueConstraintAndReplay(): void
    {
        $quote = $this->makeQuoteWithBreaks();
        $token = $quote->getPublicToken();

        $first = $this->service->acceptQuote($quote, 500, ['name' => 'First'], $token);
        $this->assertFalse($first['idempotent_replay'] ?? false);

        // Serial replay returns the ORIGINAL record.
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());
        $replay = $this->service->acceptQuote($quote, 500, ['name' => 'Second'], $token);
        $this->assertTrue($replay['idempotent_replay']);
        $this->assertSame($first['acceptance_id'], $replay['acceptance_id']);
        $this->assertSame('First', $this->em->find(QuoteAcceptance::class, $first['acceptance_id'])->getCustomerName(), 'replay must not overwrite the winner');

        // The DATABASE is the arbiter: a second row for the same quote is
        // impossible (this is what a concurrent loser hits).
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO quote_acceptances (quote_id, accepted_quantity, accepted_total, currency, accepted_at)
             VALUES (?, 1, 1.00, ?, NOW())',
            [$quote->getId(), 'USD']
        );
    }

    public function testAcceptanceGeneratesFollowOnSalesEvent(): void
    {
        $quote = $this->makeQuoteWithBreaks();

        $admin = new User();
        $admin->setEmail('ops-r8@example.com');
        $admin->setFirstName('Ops');
        $admin->setLastName('Queue');
        $admin->setPassword('x');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        $this->service->acceptQuote($quote, 250, ['name' => 'Buyer'], $quote->getPublicToken());

        $notifications = $this->em->getRepository(Notification::class)->findAll();
        $this->assertNotEmpty(
            array_filter($notifications, fn (Notification $n) => $n->getType() === Notification::TYPE_QUOTE_ACCEPTED),
            'an acceptance must notify the operations queue'
        );
    }

    public function testRequestIsRateLimitedPerToken(): void
    {
        $quote = $this->makeQuoteWithBreaks();
        $token = $quote->getPublicToken();

        for ($i = 1; $i <= 10; $i++) {
            $this->service->requestQuoteAtQuantity($quote, 100 + $i, null, $token, '203.0.113.7');
        }

        $this->expectException(RateLimitExceeded::class);
        $this->service->requestQuoteAtQuantity($quote, 200, null, $token, '203.0.113.7');
    }

    public function testRequestSurfacesIntoSalesWorkflow(): void
    {
        $quote = $this->makeQuoteWithBreaks();

        $admin = new User();
        $admin->setEmail('ops2-r8@example.com');
        $admin->setFirstName('Ops');
        $admin->setLastName('Two');
        $admin->setPassword('x');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->em->clear();
        $quote = $this->em->find(Quote::class, $quote->getId());

        $result = $this->service->requestQuoteAtQuantity($quote, 750, 'Please confirm pricing', $quote->getPublicToken(), '198.51.100.9');
        $requestId = $result['request_id'];

        // Discoverable through the salesperson's normal workflow: the queue.
        /** @var \App\Repository\QuoteCustomerRequestRepository $repo */
        $repo = $this->em->getRepository(QuoteCustomerRequest::class);
        $freshQuote = $this->em->find(Quote::class, $quote->getId());
        $open = $repo->findOpenByQuote($freshQuote);
        $this->assertCount(1, $open);
        $this->assertSame($requestId, $open[0]->getId());
        $this->assertSame(QuoteCustomerRequest::STATUS_NEW, $open[0]->getStatus());

        // Workflow transitions: new → in_review → handled (handler recorded).
        $open[0]->transitionTo(QuoteCustomerRequest::STATUS_IN_REVIEW);
        $open[0]->transitionTo(QuoteCustomerRequest::STATUS_HANDLED, 'rep@example.com', 'Formal quote sent');
        $this->em->flush();

        $this->em->clear();
        $handled = $this->em->find(QuoteCustomerRequest::class, $requestId);
        $this->assertSame(QuoteCustomerRequest::STATUS_HANDLED, $handled->getStatus());
        $this->assertSame('rep@example.com', $handled->getHandledBy());
        $this->assertSame('Formal quote sent', $handled->getResolutionNotes());
        $this->assertNotNull($handled->getHandledAt());
        $this->assertCount(0, $repo->findOpenByQuote($freshQuote));

        // And the operations queue was notified.
        $notifications = $this->em->getRepository(Notification::class)->findAll();
        $this->assertNotEmpty(
            array_filter($notifications, fn (Notification $n) => $n->getType() === Notification::TYPE_QUOTE_REQUEST),
            'a customer request must notify the operations queue'
        );
    }
}
