<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Contact\ContactQualityScorer;
use App\Service\WebCrawler\Contact\LinkedInProfileParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkedIn profile parsing, contact scoring, and deduplication.
 */
class ContactEnrichmentTest extends TestCase
{
    private LinkedInProfileParser $parser;
    private ContactQualityScorer $scorer;

    protected function setUp(): void
    {
        $this->parser = new LinkedInProfileParser();
        $this->scorer = new ContactQualityScorer();
    }

    // ═══════════════════════════════════════════════════
    // LinkedInProfileParser — Title parsing
    // ═══════════════════════════════════════════════════

    public function testParseStandardProfileTitle(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/john-smith-12345',
            'John Smith - Procurement Manager at ACME Corp | LinkedIn',
            'John Smith · Berlin, Germany · 500+ connections',
        );

        $this->assertNotNull($result);
        $this->assertEquals('John', $result['first_name']);
        $this->assertEquals('Smith', $result['last_name']);
        $this->assertEquals('Procurement Manager', $result['job_title']);
        $this->assertEquals('ACME Corp', $result['company_mentioned']);
        $this->assertEquals('https://linkedin.com/in/john-smith-12345', $result['linkedin_url']);
        $this->assertEquals(100, $result['role_score']); // procurement = 100
    }

    public function testParseProfileWithCredentials(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/ramzi-bejaoui-12345',
            'Ramzi Bejaoui, PMP®, PMI-RMP® - Senior Buyer | LinkedIn',
            '',
        );

        $this->assertNotNull($result);
        $this->assertEquals('Ramzi', $result['first_name']);
        $this->assertEquals('Bejaoui', $result['last_name']);
        $this->assertEquals('Senior Buyer', $result['job_title']);
        $this->assertGreaterThanOrEqual(95, $result['role_score']); // buyer = 95
    }

    public function testParseFrenchTitle(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/jean-dupont-abcd',
            'Jean Dupont – Directeur Achats – Renault SAS | LinkedIn',
            '',
        );

        $this->assertNotNull($result);
        $this->assertEquals('Jean', $result['first_name']);
        $this->assertEquals('Dupont', $result['last_name']);
        $this->assertEquals('Directeur Achats', $result['job_title']);
        $this->assertGreaterThanOrEqual(50, $result['role_score']);
    }

    public function testParseGermanTitle(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/hans-mueller-abc123',
            'Hans Mueller - Leiter Einkauf - Bosch GmbH | LinkedIn',
            'Hans Mueller · Stuttgart, Deutschland · 200+ connections',
        );

        $this->assertNotNull($result);
        $this->assertEquals('Hans', $result['first_name']);
        $this->assertEquals('Mueller', $result['last_name']);
        $this->assertStringContainsString('Einkauf', $result['job_title'] ?? '');
        $this->assertGreaterThanOrEqual(50, $result['role_score']); // leiter + einkauf
    }

    public function testParseNonLinkedInUrlReturnsNull(): void
    {
        $result = $this->parser->parseProfile(
            'https://example.com/john-smith',
            'John Smith - Procurement Manager',
            '',
        );

        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════════════
    // LinkedInProfileParser — Slug parsing
    // ═══════════════════════════════════════════════════

    public function testParseSlugWithHashSuffix(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/maria-rossi-ab12cd34',
            '',  // Empty title forces slug fallback
            'Procurement Manager at Marelli',
        );

        $this->assertNotNull($result);
        $this->assertEquals('Maria', $result['first_name']);
        $this->assertEquals('Rossi', $result['last_name']);
        $this->assertEquals('slug_parse', $result['source_method']);
        // Job title should be enriched from snippet
        $this->assertNotEmpty($result['job_title'] ?? '');
    }

    public function testParseSlugWithNumericSuffix(): void
    {
        $result = $this->parser->parseProfile(
            'https://linkedin.com/in/pierre-martin-123456',
            '',
            '',
        );

        $this->assertNotNull($result);
        $this->assertEquals('Pierre', $result['first_name']);
        $this->assertEquals('Martin', $result['last_name']);
    }

    // ═══════════════════════════════════════════════════
    // LinkedInProfileParser — Company pages
    // ═══════════════════════════════════════════════════

    public function testParseCompanyPage(): void
    {
        $result = $this->parser->parseCompanyPage(
            'https://linkedin.com/company/sick-ag',
            'SICK AG | LinkedIn',
            'SICK AG | 5,001-10,000 employees | Sensor Intelligence. Learn about working at SICK AG.',
        );

        $this->assertNotNull($result);
        $this->assertEquals('SICK AG', $result['company_name']);
        $this->assertNotEmpty($result['employee_hint']);
        $this->assertStringContainsString('linkedin.com/company/sick-ag', $result['linkedin_company_url']);
    }

    public function testParseCompanyPageNonCompanyUrlReturnsNull(): void
    {
        $result = $this->parser->parseCompanyPage(
            'https://linkedin.com/in/john-smith',
            'John Smith | LinkedIn',
            '',
        );

        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════════════
    // LinkedInProfileParser — Snippet extraction
    // ═══════════════════════════════════════════════════

    public function testExtractTitleFromSnippet(): void
    {
        $title = $this->parser->extractTitleFromSnippet(
            'Senior Procurement Manager at ACME Corp · Berlin, Germany',
        );

        $this->assertNotNull($title);
        $this->assertStringContainsString('Procurement', $title);
    }

    public function testExtractLocationFromSnippet(): void
    {
        $location = $this->parser->extractLocationFromSnippet(
            'John Smith · Berlin, Germany · 500+ connections',
        );

        $this->assertNotNull($location);
        $this->assertStringContainsString('Berlin', $location);
        $this->assertStringContainsString('Germany', $location);
    }

    // ═══════════════════════════════════════════════════
    // LinkedInProfileParser — Role scoring
    // ═══════════════════════════════════════════════════

    public function testRoleScoreProcurementIsHighest(): void
    {
        $this->assertEquals(100, $this->parser->computeRoleScore('Procurement Manager'));
        $this->assertEquals(100, $this->parser->computeRoleScore('Purchasing Director'));
        $this->assertGreaterThanOrEqual(85, $this->parser->computeRoleScore('Supply Chain Manager'));
    }

    public function testRoleScoreEngineeringIsMedium(): void
    {
        $score = $this->parser->computeRoleScore('Engineering Manager');
        $this->assertGreaterThanOrEqual(45, $score);
        $this->assertLessThan(100, $score);
    }

    public function testRoleScoreCEOIsLower(): void
    {
        $score = $this->parser->computeRoleScore('CEO');
        $this->assertGreaterThan(0, $score);
        $this->assertLessThan(50, $score);
    }

    public function testRoleScoreEmptyIsZero(): void
    {
        $this->assertEquals(0, $this->parser->computeRoleScore(''));
    }

    public function testRankContactsSortsByRoleScore(): void
    {
        $contacts = [
            ['first_name' => 'A', 'job_title' => 'CEO'],
            ['first_name' => 'B', 'job_title' => 'Procurement Manager'],
            ['first_name' => 'C', 'job_title' => 'Engineering Lead'],
        ];

        $ranked = $this->parser->rankContacts($contacts);

        $this->assertEquals('B', $ranked[0]['first_name']); // Procurement first
        $this->assertEquals('C', $ranked[1]['first_name']); // Engineering second
        $this->assertEquals('A', $ranked[2]['first_name']); // CEO third
    }

    // ═══════════════════════════════════════════════════
    // ContactQualityScorer — Scoring
    // ═══════════════════════════════════════════════════

    public function testScoreHighQualityContact(): void
    {
        $score = $this->scorer->score([
            'first_name'     => 'John',
            'last_name'      => 'Smith',
            'email'          => 'john.smith@acme.com',
            'phone'          => '+49 123 456',
            'linkedin_url'   => 'https://linkedin.com/in/john-smith',
            'job_title'      => 'Procurement Manager',
            'source'         => 'schema_org',
            'role_score'     => 100,
            'company_domain' => 'acme.com',
        ]);

        // Should be very high: 100 (role) + 30 (schema) + 20+15+10+10+5+5 (fields) + 15 (email match) = 210
        $this->assertGreaterThanOrEqual(200, $score);
    }

    public function testScoreLowQualityContact(): void
    {
        $score = $this->scorer->score([
            'first_name' => 'A',
            'last_name'  => 'B',
            'source'     => 'snippet_text',
        ]);

        // Low: 0 (role) + 5 (snippet) + 5+5 (name) = 15
        $this->assertLessThan(ContactQualityScorer::MIN_QUALITY_SCORE, $score);
    }

    public function testEmailDomainMatchBonus(): void
    {
        $withMatch = $this->scorer->score([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@acme.com',
            'company_domain' => 'acme.com',
            'source' => 'mailto_link',
        ]);

        $withoutMatch = $this->scorer->score([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@gmail.com',
            'company_domain' => 'acme.com',
            'source' => 'mailto_link',
        ]);

        $this->assertGreaterThan($withoutMatch, $withMatch);
    }

    // ═══════════════════════════════════════════════════
    // ContactQualityScorer — Deduplication
    // ═══════════════════════════════════════════════════

    public function testDeduplicateByName(): void
    {
        $contacts = $this->scorer->scoreAndSort([
            [
                'first_name' => 'John',
                'last_name' => 'Smith',
                'email' => 'john@acme.com',
                'source' => 'mailto_link',
            ],
            [
                'first_name' => 'John',
                'last_name' => 'Smith',
                'linkedin_url' => 'https://linkedin.com/in/john-smith',
                'job_title' => 'Procurement Manager',
                'source' => 'linkedin_title',
                'role_score' => 100,
            ],
        ]);

        $deduped = $this->scorer->deduplicate($contacts);

        $this->assertCount(1, $deduped);
        // Merged record should have both email and LinkedIn URL
        $merged = $deduped[0];
        $this->assertEquals('john@acme.com', $merged['email']);
        $this->assertStringContainsString('linkedin.com', $merged['linkedin_url']);
    }

    public function testDeduplicateKeepsDifferentPeople(): void
    {
        $contacts = $this->scorer->scoreAndSort([
            ['first_name' => 'John', 'last_name' => 'Smith', 'source' => 'team_page'],
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'source' => 'team_page'],
        ]);

        $deduped = $this->scorer->deduplicate($contacts);
        $this->assertCount(2, $deduped);
    }

    // ═══════════════════════════════════════════════════
    // ContactQualityScorer — Full pipeline
    // ═══════════════════════════════════════════════════

    public function testPipelineFiltersLowQualityContacts(): void
    {
        $contacts = [
            [
                'first_name' => 'Good',
                'last_name' => 'Contact',
                'email' => 'good@acme.com',
                'linkedin_url' => 'https://linkedin.com/in/good-contact',
                'job_title' => 'Procurement Director',
                'source' => 'schema_org',
                'role_score' => 100,
            ],
            [
                'first_name' => 'Bad',
                'last_name' => 'Contact',
                'source' => 'snippet_text',
                // No email, no phone, no LinkedIn, no job title
            ],
        ];

        $result = $this->scorer->pipeline($contacts, 5, ContactQualityScorer::MIN_QUALITY_SCORE);

        // Good contact should pass, bad should be filtered
        $this->assertCount(1, $result);
        $this->assertEquals('Good', $result[0]['first_name']);
    }

    public function testPipelineRespectsMaxContacts(): void
    {
        $contacts = [];
        for ($i = 0; $i < 10; $i++) {
            $contacts[] = [
                'first_name'   => "Person$i",
                'last_name'    => "Last$i",
                'email'        => "person$i@acme.com",
                'linkedin_url' => "https://linkedin.com/in/person-$i",
                'job_title'    => 'Procurement Manager',
                'source'       => 'team_page',
                'role_score'   => 100,
            ];
        }

        $result = $this->scorer->pipeline($contacts, 3);
        $this->assertCount(3, $result);
    }
}
