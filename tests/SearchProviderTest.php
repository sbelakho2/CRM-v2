<?php

namespace App\Tests;

use App\Service\WebCrawler\SearchProvider\GoogleCSEProvider;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\SearchProvider\SearchResult;
use App\Service\WebCrawler\SearchProvider\SearchResultSet;
use App\Service\WebCrawler\GoogleDorkService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the Search Provider Abstraction layer (Improvement 1A).
 *
 * Validates:
 *   1. Interface is properly wired in the DI container
 *   2. GoogleCSEProvider implements the interface
 *   3. SearchResult and SearchResultSet value objects work correctly
 *   4. Legacy array conversion round-trips correctly
 *   5. GoogleDorkService receives the provider via autowiring
 */
class SearchProviderTest extends KernelTestCase
{
    public function testInterfaceIsWiredInContainer(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $provider = $container->get(SearchProviderInterface::class);
        $this->assertInstanceOf(GoogleCSEProvider::class, $provider);
        $this->assertSame('google_cse', $provider->getProviderName());
        $this->assertTrue($provider->isAvailable());
    }

    public function testGoogleDorkServiceReceivesProvider(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $dorkService = $container->get(GoogleDorkService::class);
        $this->assertInstanceOf(GoogleDorkService::class, $dorkService);

        // Use reflection to verify the search provider was injected
        $ref = new \ReflectionClass($dorkService);
        $prop = $ref->getProperty('searchProvider');
        $value = $prop->getValue($dorkService);

        $this->assertInstanceOf(SearchProviderInterface::class, $value);
        $this->assertInstanceOf(GoogleCSEProvider::class, $value);
    }

    public function testSearchResultValueObject(): void
    {
        $result = new SearchResult(
            url: 'https://www.acme-aerospace.com/products',
            title: 'ACME Aerospace - Products',
            snippet: 'Leading manufacturer of aerospace components...',
            displayLink: 'www.acme-aerospace.com',
            formattedUrl: 'https://www.acme-aerospace.com/products',
            metadata: ['cacheId' => 'abc123'],
        );

        $this->assertSame('https://www.acme-aerospace.com/products', $result->getUrl());
        $this->assertSame('ACME Aerospace - Products', $result->getTitle());
        $this->assertSame('acme-aerospace.com', $result->getRootDomain());
        $this->assertSame(['cacheId' => 'abc123'], $result->getMetadata());

        // Legacy array
        $legacy = $result->toLegacyArray();
        $this->assertSame('https://www.acme-aerospace.com/products', $legacy['link']);
        $this->assertSame('ACME Aerospace - Products', $legacy['title']);
        $this->assertSame('abc123', $legacy['cacheId']);
    }

    public function testSearchResultSetValueObject(): void
    {
        $r1 = new SearchResult('https://a.com', 'A', 'Snippet A', 'a.com');
        $r2 = new SearchResult('https://b.com', 'B', 'Snippet B', 'b.com');

        $set = new SearchResultSet(
            results: [$r1, $r2],
            totalResults: 42,
            searchTimeSeconds: 0.35,
            providerName: 'test_provider',
            query: 'test query',
        );

        $this->assertCount(2, $set);
        $this->assertSame(42, $set->getTotalResults());
        $this->assertSame(0.35, $set->getSearchTimeSeconds());
        $this->assertSame('test_provider', $set->getProviderName());
        $this->assertFalse($set->isEmpty());

        // Filter
        $filtered = $set->filter(fn(SearchResult $r) => $r->getUrl() === 'https://a.com');
        $this->assertCount(1, $filtered);
        $this->assertSame('https://a.com', $filtered->getResults()[0]->getUrl());

        // Iteration
        $urls = [];
        foreach ($set as $result) {
            $urls[] = $result->getUrl();
        }
        $this->assertSame(['https://a.com', 'https://b.com'], $urls);
    }

    public function testLegacyArrayRoundTrip(): void
    {
        $legacyData = [
            'results' => [
                [
                    'title' => 'ACME Corp',
                    'link' => 'https://acme.com',
                    'snippet' => 'Manufacturing company...',
                    'displayLink' => 'acme.com',
                    'formattedUrl' => 'https://acme.com',
                    'htmlSnippet' => '<b>Manufacturing</b> company...',
                    'cacheId' => 'xyz',
                    'pagemap' => ['metatags' => []],
                ],
            ],
            'totalResults' => 100,
            'searchTime' => 0.42,
        ];

        $resultSet = SearchResultSet::fromLegacyArray($legacyData, 'google_cse', 'test query');

        $this->assertCount(1, $resultSet);
        $this->assertSame(100, $resultSet->getTotalResults());
        $this->assertSame(0.42, $resultSet->getSearchTimeSeconds());

        $first = $resultSet->getResults()[0];
        $this->assertSame('https://acme.com', $first->getUrl());
        $this->assertSame('ACME Corp', $first->getTitle());

        // Round-trip back to legacy
        $roundTrip = $resultSet->toLegacyArray();
        $this->assertSame('https://acme.com', $roundTrip['results'][0]['link']);
        $this->assertSame('ACME Corp', $roundTrip['results'][0]['title']);
        $this->assertSame(100, $roundTrip['totalResults']);
    }

    public function testEmptySearchResultSet(): void
    {
        $empty = new SearchResultSet([], 0, 0.0, 'test');
        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);

        $legacy = $empty->toLegacyArray();
        $this->assertSame([], $legacy['results']);
        $this->assertSame(0, $legacy['totalResults']);
    }
}
