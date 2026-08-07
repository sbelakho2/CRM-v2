<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Service\FastWebScraperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * SSRF guard tests: the scraper must refuse internal/private/reserved
 * targets (cloud metadata, RFC1918, loopback, link-local, IPv6 private)
 * and unresolvable hosts, while accepting public hosts.
 */
class FastWebScraperServiceTest extends TestCase
{
    private FastWebScraperService $service;

    protected function setUp(): void
    {
        $this->service = new FastWebScraperService($this->createMock(LoggerInterface::class));
    }

    private function invoke(string $method, ...$args): mixed
    {
        $ref = new ReflectionMethod(FastWebScraperService::class, $method);
        return $ref->invoke($this->service, ...$args);
    }

    /**
     * @dataProvider privateTargetProvider
     */
    public function testNormalizeUrlRejectsInternalTargets(string $url): void
    {
        $this->assertNull($this->invoke('normalizeUrl', $url), "$url must be rejected");
    }

    public static function privateTargetProvider(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'link-local ipv4' => ['http://169.254.1.1/'],
            'loopback' => ['http://127.0.0.1:8080/'],
            'localhost hostname' => ['http://localhost/'],
            'rfc1918 10/8' => ['http://10.0.0.1/'],
            'rfc1918 172.16/12' => ['http://172.16.5.5/'],
            'rfc1918 192.168/16' => ['http://192.168.1.50/'],
            'zero' => ['http://0.0.0.0/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 unspecified' => ['http://[::]/'],
            'ipv6 unique local' => ['http://[fc00::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'],
        ];
    }

    public function testNormalizeUrlRejectsNonHttpSchemes(): void
    {
        $this->assertNull($this->invoke('normalizeUrl', 'ftp://example.com/file'));
        $this->assertNull($this->invoke('normalizeUrl', 'file:///etc/passwd'));
        $this->assertNull($this->invoke('normalizeUrl', 'gopher://example.com/'));
    }

    public function testNormalizeUrlRejectsGarbage(): void
    {
        $this->assertNull($this->invoke('normalizeUrl', ''));
        $this->assertNull($this->invoke('normalizeUrl', 'not a url at all'));
        $this->assertNull($this->invoke('normalizeUrl', 'http://'));
    }

    public function testNormalizeUrlAcceptsPublicHttpAndHttps(): void
    {
        $http = $this->invoke('normalizeUrl', 'http://example.com/');
        $this->assertNotNull($http);
        $this->assertStringStartsWith('http://example.com', $http);

        $https = $this->invoke('normalizeUrl', 'https://example.com/page');
        $this->assertNotNull($https);
        $this->assertStringStartsWith('https://example.com', $https);
    }

    public function testPrivateIpv4Detection(): void
    {
        foreach (['10.0.0.1', '172.16.0.1', '172.31.255.255', '192.168.0.1', '127.0.0.1', '169.254.169.254', '0.0.0.0'] as $ip) {
            $this->assertTrue($this->invoke('isPrivateIpv4', $ip), "$ip must be private");
        }
        foreach (['8.8.8.8', '1.1.1.1', '172.15.0.1', '172.32.0.1', '192.169.0.1'] as $ip) {
            $this->assertFalse($this->invoke('isPrivateIpv4', $ip), "$ip must be public");
        }
    }

    public function testPrivateIpv6Detection(): void
    {
        foreach (['::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1'] as $ip) {
            $this->assertTrue($this->invoke('isPrivateIpv6', $ip), "$ip must be private");
        }
        foreach (['2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) {
            $this->assertFalse($this->invoke('isPrivateIpv6', $ip), "$ip must be public");
        }
    }

    public function testBatchScrapeSilentlySkipsBlockedTargets(): void
    {
        // Only internal targets: batchScrape must not fetch anything and
        // must not crash; blocked sites report zero scraped pages.
        $result = $this->service->batchScrape([
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1/',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        foreach ($result as $entry) {
            $this->assertSame(0, $entry['pagesScraped']);
            $this->assertSame([], $entry['pages']);
            $this->assertSame(0, $entry['totalContacts']);
        }
    }
}
