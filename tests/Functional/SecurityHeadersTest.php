<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Security-header contract. These headers are the browser-side half of the
 * armor: XSS blast-radius (CSP), content-type sniffing (nosniff), framing
 * (DENY), referrer leakage and browser-feature exposure. A regression here
 * is invisible in the UI — it must be a build failure, not a discovery in
 * a pentest report.
 *
 * Detailed expectations (matching nortons.yaml/config exactly):
 * - X-Frame-Options: DENY — the CRM must never be framed.
 * - X-Content-Type-Options: nosniff.
 * - Referrer-Policy: strict-origin-when-cross-origin.
 * - Permissions-Policy: camera/microphone/geolocation denied.
 * - CSP: default-src 'self'; frame-ancestors 'self'; object-src 'none';
 *   base-uri 'self'; form-action 'self' (injection/DOM-armor directives).
 */
class SecurityHeadersTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginResponseCarriesFullHeaderArmor(): void
    {
        $this->client->request('GET', '/login');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'), 'the CRM must never be framed');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp, 'CSP framing armor must match X-Frame-Options');
        $this->assertStringContainsString("object-src 'none'", $csp, 'plugin content must be fully denied');
        $this->assertStringContainsString("base-uri 'self'", $csp, 'baseline-URI hijack must be denied');
        $this->assertStringContainsString("form-action 'self'", $csp, 'form exfiltration to foreign origins must be denied');
    }

    public function testErrorResponsesCarryTheSameArmor(): void
    {
        // 404s are attacker-controlled URLs' favorite mirror — the armor
        // must not vanish on error paths.
        $this->client->request('GET', '/definitely-not-a-route-9d31ac');
        $response = $this->client->getResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }
}
