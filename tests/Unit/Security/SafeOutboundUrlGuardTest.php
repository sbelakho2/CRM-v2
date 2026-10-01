<?php

namespace App\Tests\Unit\Security;

use App\Security\SafeOutboundUrlGuard;
use App\Security\UnsafeOutboundUrlException;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial coverage for the centralized SSRF guard: every classic
 * server-side-request-forgery vector must be rejected, and legitimate
 * public URLs must keep working.
 */
class SafeOutboundUrlGuardTest extends TestCase
{
    private SafeOutboundUrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SafeOutboundUrlGuard();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function attackUrlProvider(): iterable
    {
        yield 'loopback ipv4' => ['http://127.0.0.1/admin'];
        yield 'loopback ipv4 high port' => ['http://127.0.0.1:8080/'];
        yield 'localhost name' => ['http://localhost/secret'];
        yield 'localhost subdomain' => ['http://api.localhost/'];
        yield 'dotless intranet name' => ['http://intranet/'];
        yield 'dotless single label' => ['http://printer/'];
        yield 'private 10/8' => ['http://10.0.0.5/'];
        yield 'private 172.16/12' => ['http://172.16.0.1/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/router'];
        yield 'cloud metadata endpoint' => ['http://169.254.169.254/latest/meta-data/iam/'];
        yield 'link-local metadata alt' => ['http://169.254.170.2/creds'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/'];
        yield 'ipv6 ula' => ['http://[fc00::1234]/'];
        yield 'ipv6 ula fd00' => ['http://[fd12:3456:789a::1]/'];
        yield 'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ipv4-mapped private' => ['http://[::ffff:10.1.2.3]/'];
        yield 'unspecified address' => ['http://0.0.0.0/'];
        yield 'documentation range ipv6' => ['http://[2001:db8::1]/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://example.com/_GET%20/x'];
        yield 'ftp scheme' => ['ftp://example.com/file'];
        yield 'dict scheme' => ['dict://example.com:11211/stat'];
        yield 'embedded credentials' => ['http://user:secret@example.com/'];
        yield 'dangerous port ssh' => ['http://example.com:22/'];
        yield 'dangerous port redis' => ['http://example.com:6379/'];
        yield 'dangerous port mysql' => ['http://example.com:3306/'];
        yield 'control characters' => ["http://example.com/\r\nX-Injected: 1"];
    }

    /**
     * @dataProvider attackUrlProvider
     */
    public function testAttackVectorsAreRejected(string $url): void
    {
        $this->expectException(UnsafeOutboundUrlException::class);
        $this->guard->assertAllowed($url);
    }

    public function testLegitimatePublicUrlsAreAllowed(): void
    {
        // Resolves to public addresses in CI/local networks.
        $allowed = [
            'https://www.google.com/',
            'http://example.com/',
            'https://github.com:443/org/repo',
        ];

        foreach ($allowed as $url) {
            $this->assertSame($url, $this->guard->assertAllowed($url));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function childPolicyProvider(): iterable
    {
        yield 'same host' => ['https://www.acme.com/page', 'https://www.acme.com/sitemap.xml', true];
        yield 'www variance' => ['https://acme.com/', 'https://www.acme.com/sitemap.xml', true];
        yield 'subdomain of base' => ['https://acme.com/', 'https://shop.acme.com/page', true];
        yield 'base of subdomain' => ['https://shop.acme.com/', 'https://acme.com/page', true];
        yield 'different org' => ['https://acme.com/', 'https://evil.com/sitemap.xml', false];
        yield 'lookalike suffix' => ['https://acme.com/', 'https://acme.com.evil.com/sitemap.xml', false];
        yield 'evil subdomain mimic' => ['https://acme.com/', 'https://evil-acme.com/', false];
    }

    /**
     * @dataProvider childPolicyProvider
     */
    public function testSameOrganizationPolicy(string $base, string $candidate, bool $expected): void
    {
        $this->assertSame($expected, $this->guard->isAllowedChildUrl($base, $candidate));
    }

    public function testPublicIpClassification(): void
    {
        $this->assertTrue(SafeOutboundUrlGuard::isPubliclyRoutableIp('8.8.8.8'));
        $this->assertTrue(SafeOutboundUrlGuard::isPubliclyRoutableIp('2606:4700:4700::1111'));

        $private = [
            '127.0.0.1', '10.1.2.3', '172.31.255.255', '192.168.0.1',
            '169.254.169.254', '0.0.0.0', '224.0.0.1', '100.64.0.1',
            '::1', 'fe80::1', 'fc00::1', 'fdab::1', '::ffff:127.0.0.1', '::ffff:169.254.1.1',
        ];
        foreach ($private as $ip) {
            $this->assertFalse(SafeOutboundUrlGuard::isPubliclyRoutableIp($ip), "{$ip} must not be considered publicly routable");
        }

        $this->assertFalse(SafeOutboundUrlGuard::isPubliclyRoutableIp('not-an-ip'));
    }

    // ── Critical-surface additions: credential-endpoint gate + resolveHost ──

    public function testCredentialEndpointsRequireHttps(): void
    {
        // 1.1.1.1 is a public literal: assertAllowed passes, the HTTPS rule
        // is what must reject it.
        try {
            $this->guard->assertAllowedCredentialEndpoint('http://1.1.1.1/portal/login');
            $this->fail('credential-bearing requests over http:// must be rejected');
        } catch (UnsafeOutboundUrlException $e) {
            self::assertStringContainsString('HTTPS', $e->getMessage());
        }
    }

    public function testCredentialEndpointsRejectNonDefaultPorts(): void
    {
        try {
            $this->guard->assertAllowedCredentialEndpoint('https://1.1.1.1:8443/portal/login');
            $this->fail('credential endpoints must not target non-default ports');
        } catch (UnsafeOutboundUrlException $e) {
            self::assertStringContainsString('port', strtolower($e->getMessage()));
        }
    }

    public function testCredentialEndpointOnDefaultHttpsPortPasses(): void
    {
        self::assertSame('https://1.1.1.1/supplier-portal', $this->guard->assertAllowedCredentialEndpoint('https://1.1.1.1/supplier-portal'));
    }

    public function testCloudMetadataIpIsExplicitlyBlocked(): void
    {
        // The single most valuable SSRF target in any cloud deployment.
        $this->expectException(UnsafeOutboundUrlException::class);
        $this->guard->assertAllowed('http://169.254.169.254/latest/meta-data/');
    }

    public function testResolveHostReturnsLoopbackForLocalhost(): void
    {
        $ips = $this->guard->resolveHost('localhost');
        self::assertNotEmpty($ips, 'localhost must resolve on every runner');
        self::assertContains('127.0.0.1', $ips);
    }

    public function testResolveHostOfUnresolvableNameYieldsNothing(): void
    {
        self::assertSame([], $this->guard->resolveHost('no-such-host-4f3ae2.invalid-extra'));
    }
}
