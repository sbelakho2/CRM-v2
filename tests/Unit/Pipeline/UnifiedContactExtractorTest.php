<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CrawledDomain;
use App\Service\WebCrawler\Pipeline\CrawledPage;
use App\Service\WebCrawler\Pipeline\ExtractedContact;
use App\Service\WebCrawler\Pipeline\UnifiedContactExtractor;
use PHPUnit\Framework\TestCase;

class UnifiedContactExtractorTest extends TestCase
{
    private UnifiedContactExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new UnifiedContactExtractor();
    }

    // ───────────────────── helpers ─────────────────────

    private function makeDomain(
        string $html,
        string $domainName = 'example.com',
        array $structuredData = [],
    ): CrawledDomain {
        return new CrawledDomain($domainName, [
            new CrawledPage(
                "https://{$domainName}",
                $html,
                200,
                'homepage',
                $structuredData,
            ),
        ], 0.1);
    }

    private function makeMultiPageDomain(array $pages): CrawledDomain
    {
        $crawledPages = [];
        foreach ($pages as $i => $pageHtml) {
            $crawledPages[] = new CrawledPage(
                'https://example.com' . ($i === 0 ? '' : '/page' . $i),
                $pageHtml,
                200,
                $i === 0 ? 'homepage' : 'team',
            );
        }
        return new CrawledDomain('example.com', $crawledPages, 0.1);
    }

    // ───────────────────── email extraction ─────────────────────

    /** @test */
    public function extractsEmailFromMailtoLink(): void
    {
        $domain = $this->makeDomain(
            '<html><body><a href="mailto:john.doe@example.com">Contact John</a></body></html>',
        );

        $contacts = $this->extractor->extract($domain);
        $emails = array_map(fn(ExtractedContact $c) => $c->getEmail(), $contacts);

        $this->assertContains('john.doe@example.com', $emails);
    }

    /** @test */
    public function extractsEmailFromVisibleText(): void
    {
        $domain = $this->makeDomain(
            '<html><body><p>Email john.doe@example.com for inquiries.</p></body></html>',
        );

        $contacts = $this->extractor->extract($domain);
        $emails = array_filter(array_map(fn(ExtractedContact $c) => $c->getEmail(), $contacts));

        $this->assertContains('john.doe@example.com', $emails);
    }

    /** @test */
    public function filtersGenericVisibleEmails(): void
    {
        $domain = $this->makeDomain(
            '<html><body><p>Email us at info@example.com for inquiries.</p></body></html>',
        );

        $contacts = $this->extractor->extract($domain);
        $emails = array_filter(array_map(fn(ExtractedContact $c) => $c->getEmail(), $contacts));

        $this->assertNotContains('info@example.com', $emails);
    }

    /** @test */
    public function rejectsInvalidEmails(): void
    {
        $domain = $this->makeDomain(
            '<html><body><a href="mailto:not-an-email">Bad</a></body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $this->assertEmpty($contacts);
    }

    // ───────────────────── phone extraction ─────────────────────

    /** @test */
    public function extractsPhoneFromTelLink(): void
    {
        $domain = $this->makeDomain(
            '<html><body><div class="team-member"><h3>Jane Doe</h3><p>Procurement Manager</p><a href="tel:+49891234567">Call us</a></div></body></html>',
        );

        $contacts = $this->extractor->extract($domain);
        $phones = array_filter(array_map(fn(ExtractedContact $c) => $c->getPhone(), $contacts));

        $this->assertNotEmpty($phones);
    }

    // ───────────────────── LinkedIn extraction ─────────────────────

    /** @test */
    public function extractsLinkedInUrls(): void
    {
        $domain = $this->makeDomain(
            '<html><body>'
            . '<a href="https://www.linkedin.com/in/john-doe">John Doe</a>'
            . '</body></html>',
        );

        $contacts = $this->extractor->extract($domain);
        $linkedin = array_filter(array_map(fn(ExtractedContact $c) => $c->getLinkedinUrl(), $contacts));

        $this->assertNotEmpty($linkedin);
    }

    // ───────────────────── JSON-LD extraction ─────────────────────

    /** @test */
    public function extractsContactFromJsonLdPerson(): void
    {
        $domain = $this->makeDomain(
            '<html><body>Content</body></html>',
            'example.com',
            [
                [
                    '@type' => 'Person',
                    'givenName' => 'Jane',
                    'familyName' => 'Smith',
                    'jobTitle' => 'CEO',
                    'email' => 'jane.smith@example.com',
                ],
            ],
        );

        $contacts = $this->extractor->extract($domain);

        $this->assertNotEmpty($contacts);
        $jane = $contacts[0];
        $this->assertSame('Jane', $jane->getFirstName());
        $this->assertSame('Smith', $jane->getLastName());
        $this->assertSame('CEO', $jane->getJobTitle());
        $this->assertSame('jane.smith@example.com', $jane->getEmail());
    }

    /** @test */
    public function extractsContactFromJsonLdContactPoint(): void
    {
        $domain = $this->makeDomain(
            '<html><body>Content</body></html>',
            'example.com',
            [
                [
                    '@type' => 'Organization',
                    'contactPoint' => [
                        '@type' => 'ContactPoint',
                        'contactType' => 'Procurement Manager',
                        'email' => 'john.doe@example.com',
                        'telephone' => '+49 89 1234567',
                    ],
                ],
            ],
        );

        $contacts = $this->extractor->extract($domain);
        $emails = array_filter(array_map(fn(ExtractedContact $c) => $c->getEmail(), $contacts));

        $this->assertContains('john.doe@example.com', $emails);
    }

    // ───────────────────── HTML team card extraction ─────────────────────

    /** @test */
    public function extractsContactFromTeamCardPattern(): void
    {
        $domain = $this->makeDomain(
            '<html><body>'
            . '<div class="team-member">'
            . '  <h3>Michael Johnson</h3>'
            . '  <p>VP of Engineering</p>'
            . '  <a href="mailto:m.johnson@example.com">Email</a>'
            . '</div>'
            . '</body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $michael = null;
        foreach ($contacts as $c) {
            if ($c->getFirstName() === 'Michael') {
                $michael = $c;
                break;
            }
        }
        $this->assertNotNull($michael);
        $this->assertSame('Johnson', $michael->getLastName());
        $this->assertSame('VP of Engineering', $michael->getJobTitle());
    }

    // ───────────────────── deduplication ─────────────────────

    /** @test */
    public function deduplicatesByEmail(): void
    {
        $domain = $this->makeMultiPageDomain([
            '<html><body><a href="mailto:john@example.com">John</a></body></html>',
            '<html><body><a href="mailto:john@example.com">John Doe</a></body></html>',
        ]);

        $contacts = $this->extractor->extract($domain);
        $emails = array_map(fn(ExtractedContact $c) => $c->getEmail(), $contacts);
        $emailCounts = array_count_values(array_filter($emails));

        // john@example.com should appear at most once
        $this->assertLessThanOrEqual(1, $emailCounts['john@example.com'] ?? 0);
    }

    // ───────────────────── quality scoring ─────────────────────

    /** @test */
    public function completeContactScoresHigherThanPartial(): void
    {
        $domain = $this->makeDomain(
            '<html><body>'
            . '<div class="team-member">'
            . '  <h3>Alice Brown</h3>'
            . '  <p>Director of Sales</p>'
            . '  <a href="mailto:alice.brown@example.com">Email</a>'
            . '  <a href="https://linkedin.com/in/alicebrown">LinkedIn</a>'
            . '</div>'
            . '<a href="mailto:alex@example.com">General enquiry</a>'
            . '</body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        // Alice (name + title + email + linkedin) should score higher than alex@ (email only)
        $alice = null;
        $partial = null;
        foreach ($contacts as $c) {
            if ($c->getEmail() === 'alice.brown@example.com') {
                $alice = $c;
            }
            if ($c->getEmail() === 'alex@example.com') {
                $partial = $c;
            }
        }

        $this->assertNotNull($alice);
        $this->assertNotNull($partial);
        $this->assertGreaterThan($partial->getQualityScore(), $alice->getQualityScore());
    }

    /** @test */
    public function domainMatchingEmailScoresHigher(): void
    {
        $domain = $this->makeDomain(
            '<html><body>'
            . '<a href="mailto:john.smith@example.com">John</a>'
            . '<a href="mailto:jane.smith@gmail.com">Jane Personal</a>'
            . '</body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $domainMatch = null;
        $nonMatch = null;
        foreach ($contacts as $c) {
            if ($c->getEmail() === 'john.smith@example.com') {
                $domainMatch = $c;
            }
            if ($c->getEmail() === 'jane.smith@gmail.com') {
                $nonMatch = $c;
            }
        }

        $this->assertNotNull($domainMatch);
        $this->assertNotNull($nonMatch);
        $this->assertGreaterThan($nonMatch->getQualityScore(), $domainMatch->getQualityScore());
    }

    // ───────────────────── edge cases ─────────────────────

    /** @test */
    public function returnsEmptyForEmptyDomain(): void
    {
        $domain = new CrawledDomain('ghost.com', [], 0.1);

        $contacts = $this->extractor->extract($domain);

        $this->assertSame([], $contacts);
    }

    /** @test */
    public function returnsEmptyForNoContactContent(): void
    {
        $domain = $this->makeDomain(
            '<html><body><p>We manufacture precision parts.</p></body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $this->assertSame([], $contacts);
    }

    /** @test */
    public function contactsSortedByQualityDescending(): void
    {
        $domain = $this->makeDomain(
            '<html><body>'
            . '<div class="team-member"><h3>Alice Brown</h3><p>CEO</p>'
            . '<a href="mailto:alice@example.com">Email</a></div>'
            . '<a href="mailto:john.smith@example.com">General</a>'
            . '</body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $this->assertCount(2, $contacts);
        $this->assertGreaterThanOrEqual(
            $contacts[1]->getQualityScore(),
            $contacts[0]->getQualityScore(),
        );
    }

    // ───────────────────── return type ─────────────────────

    /** @test */
    public function extractReturnsArrayOfExtractedContact(): void
    {
        $domain = $this->makeDomain(
            '<html><body><a href="mailto:test@example.com">Test</a></body></html>',
        );

        $contacts = $this->extractor->extract($domain);

        $this->assertIsArray($contacts);
        foreach ($contacts as $c) {
            $this->assertInstanceOf(ExtractedContact::class, $c);
        }
    }
}
