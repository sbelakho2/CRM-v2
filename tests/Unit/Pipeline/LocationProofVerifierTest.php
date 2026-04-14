<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CrawledDomain;
use App\Service\WebCrawler\Pipeline\CrawledPage;
use App\Service\WebCrawler\Pipeline\LocationProofVerifier;
use App\Service\WebCrawler\Pipeline\LocationVerdict;
use PHPUnit\Framework\TestCase;

class LocationProofVerifierTest extends TestCase
{
    private LocationProofVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new LocationProofVerifier();
    }

    // ───────────────────── helpers ─────────────────────

    private function makeDomain(
        string $domainName,
        string $text,
        array $structuredData = [],
        array $metaTags = [],
    ): CrawledDomain {
        return new CrawledDomain($domainName, [
            new CrawledPage(
                "https://{$domainName}",
                '<html><body>' . $text . '</body></html>',
                200,
                'homepage',
                $structuredData,
                $metaTags,
            ),
        ], 0.1);
    }

    // ───────────────────── null target (skip) ─────────────────────

    /** @test */
    public function confirmsWhenTargetLocationIsNull(): void
    {
        $domain = $this->makeDomain('example.com', 'Some random content.');

        $result = $this->verifier->verify($domain, null);

        $this->assertTrue($result->isConfirmed());
    }

    /** @test */
    public function confirmsWhenTargetLocationIsEmpty(): void
    {
        $domain = $this->makeDomain('example.com', 'Some random content.');

        $result = $this->verifier->verify($domain, '');

        $this->assertTrue($result->isConfirmed());
    }

    // ───────────────────── JSON-LD address ─────────────────────

    /** @test */
    public function confirmsFromJsonLdAddressLocality(): void
    {
        $domain = $this->makeDomain('acme.com', 'Welcome', [
            [
                '@type' => 'Organization',
                'name'  => 'Acme',
                'address' => [
                    '@type'            => 'PostalAddress',
                    'addressLocality'  => 'Casablanca',
                    'addressCountry'   => 'MA',
                ],
            ],
        ]);

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertTrue($result->isConfirmed());
        $this->assertArrayHasKey('json_ld_address', $result->getSignals());
    }

    /** @test */
    public function confirmsFromJsonLdAddressCountry(): void
    {
        $domain = $this->makeDomain('acme.de', 'Welcome', [
            [
                '@type' => 'LocalBusiness',
                'address' => [
                    'addressCountry' => 'Germany',
                ],
            ],
        ]);

        $result = $this->verifier->verify($domain, 'Germany');

        $this->assertTrue($result->isConfirmed());
    }

    // ───────────────────── ccTLD ─────────────────────

    /** @test */
    public function confirmsFromCcTldMatch(): void
    {
        $domain = $this->makeDomain('acme.ma', 'Content in Arabic and French.');

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertTrue($result->isConfirmed());
        $this->assertArrayHasKey('cctld', $result->getSignals());
    }

    /** @test */
    public function confirmsFromCcTldForGermany(): void
    {
        $domain = $this->makeDomain('unternehmen.de', 'German company content.');

        $result = $this->verifier->verify($domain, 'Germany');

        $this->assertTrue($result->isConfirmed());
    }

    /** @test */
    public function confirmsFromCompoundCcTld(): void
    {
        $domain = $this->makeDomain('company.co.uk', 'British engineering firm.');

        $result = $this->verifier->verify($domain, 'United Kingdom');

        $this->assertTrue($result->isConfirmed());
    }

    // ───────────────────── phone country code ─────────────────────

    /** @test */
    public function confirmsFromPhoneCountryCode(): void
    {
        $domain = $this->makeDomain('acme.com', 'Call us at +212 522 123456');

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertTrue($result->isConfirmed());
        $this->assertArrayHasKey('phone_code', $result->getSignals());
    }

    /** @test */
    public function confirmsGermanyFromPhoneCode(): void
    {
        $domain = $this->makeDomain('acme.com', 'Phone: +49 89 12345678');

        $result = $this->verifier->verify($domain, 'Germany');

        $this->assertTrue($result->isConfirmed());
    }

    // ───────────────────── text address mention ─────────────────────

    /** @test */
    public function confirmsFromLocationMentionInText(): void
    {
        // Mention the city name strongly — enough to cross threshold
        $domain = $this->makeDomain(
            'acme.com',
            'Our headquarters is in Casablanca, Morocco. We operate a factory in Casablanca.',
        );

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertTrue($result->isConfirmed());
    }

    /** @test */
    public function matchesLocationCaseInsensitively(): void
    {
        $domain = $this->makeDomain('acme.com', 'We have offices in MOROCCO and our factory in morocco.');

        $result = $this->verifier->verify($domain, 'morocco');

        $this->assertTrue($result->isConfirmed());
    }

    // ───────────────────── rejection ─────────────────────

    /** @test */
    public function rejectsWhenNoLocationSignals(): void
    {
        $domain = $this->makeDomain('acme.com', 'We are a manufacturing company. CNC machining and stamping.');

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertFalse($result->isConfirmed());
        $this->assertEmpty($result->getSignals());
    }

    /** @test */
    public function rejectsEmptyDomain(): void
    {
        $domain = new CrawledDomain('ghost.com', [], 0.1);

        $result = $this->verifier->verify($domain, 'Germany');

        $this->assertFalse($result->isConfirmed());
    }

    // ───────────────────── multiple signals ─────────────────────

    /** @test */
    public function multipleSignalsIncreaseConfidence(): void
    {
        // ccTLD + phone + text mention
        $domain = $this->makeDomain(
            'acme.ma',
            'Call us in Casablanca: +212 522 123456. Our main office is in Morocco.',
        );

        $result = $this->verifier->verify($domain, 'Morocco');

        $this->assertTrue($result->isConfirmed());
        $this->assertGreaterThanOrEqual(2, \count($result->getSignals()));
    }

    // ───────────────────── return type ─────────────────────

    /** @test */
    public function verifyReturnsLocationVerdict(): void
    {
        $domain = $this->makeDomain('acme.com', 'Content.');

        $result = $this->verifier->verify($domain, 'Germany');

        $this->assertInstanceOf(LocationVerdict::class, $result);
        $this->assertIsBool($result->isConfirmed());
        $this->assertIsFloat($result->getConfidence());
        $this->assertIsArray($result->getSignals());
    }

    // ───────────────────── alternate country names ─────────────────────

    /** @test */
    public function matchesFrenchCountryName(): void
    {
        $domain = $this->makeDomain('acme.fr', 'Bienvenue sur notre site.');

        $result = $this->verifier->verify($domain, 'France');

        $this->assertTrue($result->isConfirmed());
    }

    /**
     * @test
     * @dataProvider countryTldProvider
     */
    public function matchesVariousCountryTlds(string $domainName, string $targetLocation): void
    {
        $domain = $this->makeDomain($domainName, 'Company content.');

        $result = $this->verifier->verify($domain, $targetLocation);

        $this->assertTrue($result->isConfirmed());
    }

    public static function countryTldProvider(): array
    {
        return [
            'Morocco .ma'   => ['company.ma', 'Morocco'],
            'Germany .de'   => ['firma.de', 'Germany'],
            'France .fr'    => ['societe.fr', 'France'],
            'Italy .it'     => ['azienda.it', 'Italy'],
            'Spain .es'     => ['empresa.es', 'Spain'],
            'Turkey .tr'    => ['sirket.com.tr', 'Turkey'],
            'Poland .pl'    => ['firma.pl', 'Poland'],
            'Netherlands .nl' => ['bedrijf.nl', 'Netherlands'],
            'Belgium .be'   => ['bedrijf.be', 'Belgium'],
        ];
    }
}
