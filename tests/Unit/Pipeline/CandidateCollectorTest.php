<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CandidateCollector;
use App\Service\WebCrawler\Pipeline\CandidateSet;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\SearchProvider\SearchResult;
use App\Service\WebCrawler\SearchProvider\SearchResultSet;
use PHPUnit\Framework\TestCase;

class CandidateCollectorTest extends TestCase
{
    // ── Deduplication ───────────────────────────────────────────

    public function testDeduplicatesByRootDomain(): void
    {
        $provider = $this->createProviderReturning([
            $this->makeResult('https://www.acme.com/about', 'ACME Corp', 'ACME manufactures widgets'),
            $this->makeResult('https://acme.com/products', 'ACME Products', 'Our product line'),
            $this->makeResult('https://beta.com/home', 'Beta Inc', 'Beta makes gears'),
        ]);

        $collector = new CandidateCollector();
        $queries = [['query' => 'test query', 'type' => 'general']];
        $set = $collector->collect($queries, $provider);

        $this->assertCount(2, $set, 'Same root domain should be deduplicated');
        $this->assertNotNull($set->get('acme.com'));
        $this->assertNotNull($set->get('beta.com'));
    }

    public function testDeduplicatesAcrossMultipleQueries(): void
    {
        $callCount = 0;
        $provider = $this->createCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return $this->makeResultSet([
                    $this->makeResult('https://acme.com', 'ACME Corp', 'ACME'),
                ]);
            }
            return $this->makeResultSet([
                $this->makeResult('https://www.acme.com/page2', 'ACME Page 2', 'ACME again'),
                $this->makeResult('https://newco.com', 'NewCo Inc', 'NewCo'),
            ]);
        });

        $collector = new CandidateCollector();
        $queries = [
            ['query' => 'query 1', 'type' => 'general'],
            ['query' => 'query 2', 'type' => 'general'],
        ];
        $set = $collector->collect($queries, $provider);

        $this->assertCount(2, $set, 'Domains appearing in multiple queries should be deduplicated');
    }

    // ── Blocked TLDs ────────────────────────────────────────────

    /**
     * @dataProvider blockedTldProvider
     */
    public function testRejectsBlockedTlds(string $url, string $description): void
    {
        $provider = $this->createProviderReturning([
            $this->makeResult($url, 'Some Entity', 'Some snippet'),
            $this->makeResult('https://realcompany.com', 'Real Company', 'Manufacturing'),
        ]);

        $collector = new CandidateCollector();
        $set = $collector->collect([['query' => 'test', 'type' => 'general']], $provider);

        $domains = $set->getDomains();
        $this->assertContains('realcompany.com', $domains, 'Real company should be kept');

        foreach ($domains as $domain) {
            $this->assertStringNotContainsString(
                parse_url($url, PHP_URL_HOST),
                $domain,
                "{$description} should be rejected",
            );
        }
    }

    public static function blockedTldProvider(): array
    {
        return [
            '.gov'    => ['https://industry.gov.eg/page', '.gov domains'],
            '.edu'    => ['https://mit.edu/research', '.edu domains'],
            '.mil'    => ['https://defense.mil/contracts', '.mil domains'],
            '.int'    => ['https://wto.int/trade', '.int domains'],
            '.museum' => ['https://science.museum/exhibit', '.museum domains'],
            '.gov.uk' => ['https://trade.gov.uk/export', '.gov.uk domains'],
            '.gov.ma' => ['https://industry.gov.ma/info', '.gov.ma domains'],
            '.ac.uk'  => ['https://imperial.ac.uk/eng', '.ac.uk academic domains'],
        ];
    }

    // ── Junk domain patterns ────────────────────────────────────

    /**
     * @dataProvider junkDomainProvider
     */
    public function testRejectsJunkDomains(string $url, string $description): void
    {
        $provider = $this->createProviderReturning([
            $this->makeResult($url, 'Some Page', 'Some content'),
            $this->makeResult('https://goodmanufacturer.com', 'Good', 'Makes stuff'),
        ]);

        $collector = new CandidateCollector();
        $set = $collector->collect([['query' => 'test', 'type' => 'general']], $provider);

        $domains = $set->getDomains();
        $this->assertContains('goodmanufacturer.com', $domains);

        $host = strtolower(preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST)));
        $this->assertNotContains($host, $domains, "{$description} should be rejected");
    }

    public static function junkDomainProvider(): array
    {
        return [
            'LinkedIn'    => ['https://www.linkedin.com/company/acme', 'Social media (LinkedIn)'],
            'Facebook'    => ['https://www.facebook.com/acme', 'Social media (Facebook)'],
            'Twitter/X'   => ['https://twitter.com/acme', 'Social media (Twitter)'],
            'YouTube'     => ['https://youtube.com/watch?v=abc', 'Social media (YouTube)'],
            'Wikipedia'   => ['https://en.wikipedia.org/wiki/ACME', 'Wikipedia'],
            'Bloomberg'   => ['https://www.bloomberg.com/news/acme', 'News aggregator'],
            'Reuters'     => ['https://www.reuters.com/article/acme', 'News aggregator'],
            'Glassdoor'   => ['https://www.glassdoor.com/acme', 'Job board'],
            'Indeed'      => ['https://www.indeed.com/cmp/acme', 'Job board'],
            'Amazon'      => ['https://www.amazon.com/dp/B001', 'Marketplace'],
            'Alibaba'     => ['https://www.alibaba.com/product', 'Marketplace'],
            'Crunchbase'  => ['https://www.crunchbase.com/organization/acme', 'Directory'],
        ];
    }

    // ── Candidate cap ───────────────────────────────────────────

    public function testCapsAtConfiguredLimit(): void
    {
        // Generate 250 unique results
        $results = [];
        for ($i = 0; $i < 250; $i++) {
            $results[] = $this->makeResult(
                "https://company{$i}.com",
                "Company {$i}",
                "Manufacturing {$i}",
            );
        }

        $provider = $this->createProviderReturning($results);
        $collector = new CandidateCollector(maxCandidates: 200);
        $set = $collector->collect([['query' => 'big query', 'type' => 'general']], $provider);

        $this->assertLessThanOrEqual(200, count($set), 'Should cap at maxCandidates');
    }

    public function testCustomCapRespected(): void
    {
        $results = [];
        for ($i = 0; $i < 50; $i++) {
            $results[] = $this->makeResult("https://co{$i}.com", "Co {$i}", "Makes things");
        }

        $provider = $this->createProviderReturning($results);
        $collector = new CandidateCollector(maxCandidates: 10);
        $set = $collector->collect([['query' => 'test', 'type' => 'general']], $provider);

        $this->assertLessThanOrEqual(10, count($set));
    }

    // ── Query stats ─────────────────────────────────────────────

    public function testRecordsPerQueryResultCounts(): void
    {
        $callCount = 0;
        $provider = $this->createCallback(function () use (&$callCount) {
            $callCount++;
            $n = $callCount === 1 ? 3 : 1;
            $results = [];
            for ($i = 0; $i < $n; $i++) {
                $results[] = $this->makeResult(
                    "https://q{$callCount}c{$i}.com",
                    "Company",
                    "Snippet",
                );
            }
            return $this->makeResultSet($results);
        });

        $collector = new CandidateCollector();
        $queries = [
            ['query' => 'query alpha', 'type' => 'general'],
            ['query' => 'query beta', 'type' => 'sector_specific'],
        ];
        $set = $collector->collect($queries, $provider);

        $stats = $set->getQueryStats();
        $this->assertArrayHasKey('query alpha', $stats);
        $this->assertArrayHasKey('query beta', $stats);
        $this->assertSame(3, $stats['query alpha']);
        $this->assertSame(1, $stats['query beta']);
    }

    // ── Empty results ───────────────────────────────────────────

    public function testHandlesEmptyResults(): void
    {
        $provider = $this->createProviderReturning([]);
        $collector = new CandidateCollector();
        $set = $collector->collect([['query' => 'test', 'type' => 'general']], $provider);

        $this->assertTrue($set->isEmpty());
        $this->assertCount(0, $set);
    }

    public function testHandlesEmptyQueryList(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->never())->method('search');

        $collector = new CandidateCollector();
        $set = $collector->collect([], $provider);

        $this->assertTrue($set->isEmpty());
    }

    // ── Candidate structure ─────────────────────────────────────

    public function testCandidateHasRequiredFields(): void
    {
        $provider = $this->createProviderReturning([
            $this->makeResult('https://acme.com/about', 'ACME Corp - Automotive Manufacturer', 'We make parts in Tangier'),
        ]);

        $collector = new CandidateCollector();
        $set = $collector->collect([['query' => 'test', 'type' => 'sector_specific']], $provider);
        $candidate = $set->get('acme.com');

        $this->assertNotNull($candidate);
        $this->assertSame('acme.com', $candidate['domain']);
        $this->assertSame('https://acme.com/about', $candidate['url']);
        $this->assertSame('ACME Corp - Automotive Manufacturer', $candidate['title']);
        $this->assertSame('We make parts in Tangier', $candidate['snippet']);
        $this->assertSame('sector_specific', $candidate['query_type']);
        $this->assertNotEmpty($candidate['name'], 'Candidate must have a derived name');
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function makeResult(string $url, string $title, string $snippet): SearchResult
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        return new SearchResult(
            url: $url,
            title: $title,
            snippet: $snippet,
            displayLink: preg_replace('/^www\./', '', $host),
        );
    }

    private function makeResultSet(array $results): SearchResultSet
    {
        return new SearchResultSet(
            results: $results,
            totalResults: count($results),
            searchTimeSeconds: 0.1,
            providerName: 'test',
        );
    }

    private function createProviderReturning(array $results): SearchProviderInterface
    {
        $resultSet = $this->makeResultSet($results);
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->method('search')->willReturn($resultSet);
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getProviderName')->willReturn('test');
        return $provider;
    }

    private function createCallback(callable $callback): SearchProviderInterface
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->method('search')->willReturnCallback($callback);
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getProviderName')->willReturn('test');
        return $provider;
    }
}
