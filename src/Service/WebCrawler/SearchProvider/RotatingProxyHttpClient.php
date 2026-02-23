<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * HttpClient decorator that rotates proxy session IDs per request.
 *
 * IPRoyal residential proxies assign a new exit IP for each unique session ID.
 * By generating a fresh `_session-<random>` on every request() call, each
 * search engine request exits through a different residential IP address.
 *
 * CRITICAL IMPLEMENTATION NOTE:
 * Symfony's CurlHttpClient pools connections via curl_multi and ignores
 * per-request `proxy` options. Therefore we create a NEW CurlHttpClient
 * instance per request with the proxy baked into the constructor options.
 * This forces a fresh TCP connection through a new proxy session.
 *
 * Proxy URL format: http://user:pass_country-XX_session-RANDOM@host:port
 */
final class RotatingProxyHttpClient implements HttpClientInterface
{
    private readonly string $proxyUser;
    private readonly string $proxyPass;
    private readonly string $proxyHostPort;
    private readonly array $baseOptions;
    private LoggerInterface $logger;

    /** Track created clients so we can stream their responses */
    private array $activeClients = [];

    /**
     * High-water mark: when activeClients exceeds this count, purge
     * entries whose responses have likely been consumed to free curl
     * multi handles and their underlying file descriptors.
     */
    private const MAX_ACTIVE_CLIENTS = 50;

    public function __construct(
        array $baseOptions,
        string $proxyUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Parse the proxy URL to extract parts for session injection
        $parsed = parse_url($proxyUrl);
        $this->proxyUser = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? 12321;

        // Strip existing _session-xxx and _country-xx suffixes from password
        $pass = preg_replace('/_session-[a-zA-Z0-9]+/', '', $pass);
        $pass = preg_replace('/_country-[a-z]{2}/', '', $pass);
        $this->proxyPass = $pass;
        $this->proxyHostPort = sprintf('%s:%d', $host, $port);

        // Store base options (timeout, headers, etc.) — proxy will be set per request
        $this->baseOptions = $baseOptions;
    }

    /** European countries for diverse exit IP geolocation */
    private const EXIT_COUNTRIES = [
        'ee', 'de', 'nl', 'fr', 'pl', 'cz', 'fi', 'se',
        'at', 'be', 'dk', 'no', 'es', 'it', 'pt', 'ro',
    ];

    /**
     * Modern browser User-Agent pool for realistic fingerprinting.
     * Rotated per request along with proxy session for maximum diversity.
     */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0',
        'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/109.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:125.0) Gecko/20100101 Firefox/125.0',
    ];

    /**
     * Accept-Language headers matched to exit country for realistic geo-fingerprint.
     */
    private const COUNTRY_LANGUAGES = [
        'ee' => 'et-EE,et;q=0.9,en;q=0.8',
        'de' => 'de-DE,de;q=0.9,en;q=0.8',
        'nl' => 'nl-NL,nl;q=0.9,en;q=0.8',
        'fr' => 'fr-FR,fr;q=0.9,en;q=0.8',
        'pl' => 'pl-PL,pl;q=0.9,en;q=0.8',
        'cz' => 'cs-CZ,cs;q=0.9,en;q=0.8',
        'fi' => 'fi-FI,fi;q=0.9,en;q=0.8',
        'se' => 'sv-SE,sv;q=0.9,en;q=0.8',
        'at' => 'de-AT,de;q=0.9,en;q=0.8',
        'be' => 'nl-BE,nl;q=0.8,fr-BE;q=0.7,fr;q=0.6,en;q=0.5',
        'dk' => 'da-DK,da;q=0.9,en;q=0.8',
        'no' => 'nb-NO,nb;q=0.9,en;q=0.8',
        'es' => 'es-ES,es;q=0.9,en;q=0.8',
        'it' => 'it-IT,it;q=0.9,en;q=0.8',
        'pt' => 'pt-PT,pt;q=0.9,en;q=0.8',
        'ro' => 'ro-RO,ro;q=0.9,en;q=0.8',
    ];

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Generate a unique session ID for this request → new exit IP
        $sessionId = bin2hex(random_bytes(4)); // 8 hex chars
        $country = self::EXIT_COUNTRIES[array_rand(self::EXIT_COUNTRIES)];

        $proxyUrl = sprintf(
            'http://%s:%s_country-%s_session-%s@%s',
            $this->proxyUser,
            $this->proxyPass,
            $country,
            $sessionId,
            $this->proxyHostPort,
        );

        // ── Browser fingerprint injection ──────────────────────────────
        // Anti-bot systems (Bing, DuckDuckGo, Yahoo, Ecosia, Yandex, etc.)
        // fingerprint on the ABSENCE of modern browser headers, especially
        // Sec-Fetch-* which every real browser sends on navigation.
        // We inject these defaults UNDER scraper-provided headers so scrapers
        // can still override if needed.
        $requestHeaders = $options['headers'] ?? [];

        // Pick a random UA from our large pool (only if scraper didn't set one)
        // NOTE: Do NOT set Accept-Encoding here! Symfony's CurlResponse uses
        // the presence of this header to disable automatic gzip/deflate
        // decompression ($this->inflate = !isset(normalized_headers['accept-encoding'])).
        // If we set it, the server sends compressed data but Symfony won't
        // decompress it → scrapers receive binary garbage → 0 parsed results.
        $defaultHeaders = [
            'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => self::COUNTRY_LANGUAGES[$country] ?? 'en-US,en;q=0.9',
            'DNT' => '1',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
            'Sec-CH-UA' => '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-CH-UA-Mobile' => '?0',
            'Sec-CH-UA-Platform' => '"Windows"',
        ];

        // Merge: scraper's headers override our defaults (case-insensitive merge)
        $mergedHeaders = $defaultHeaders;
        foreach ($requestHeaders as $key => $value) {
            // Find and replace case-insensitively
            $found = false;
            foreach ($mergedHeaders as $defaultKey => $defaultValue) {
                if (strcasecmp($key, $defaultKey) === 0) {
                    unset($mergedHeaders[$defaultKey]);
                    $mergedHeaders[$key] = $value;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $mergedHeaders[$key] = $value;
            }
        }
        $options['headers'] = $mergedHeaders;

        // Create a FRESH CurlHttpClient with proxy baked in.
        // Symfony's CurlHttpClient ignores per-request proxy options because
        // it reuses curl_multi handles. Creating a new instance per request
        // is the only way to ensure each request uses a different proxy session.
        $clientOptions = array_merge($this->baseOptions, ['proxy' => $proxyUrl]);
        $client = new CurlHttpClient($clientOptions);

        $this->logger->debug('RotatingProxy: new session', [
            'session' => $sessionId,
            'country' => $country,
            'target' => mb_substr($url, 0, 80),
        ]);

        // Store client reference so stream() can find it
        $response = $client->request($method, $url, $options);
        $this->activeClients[spl_object_id($response)] = $client;

        // Prevent unbounded FD accumulation: when we exceed the high-water mark,
        // drop the oldest client references so PHP can GC the curl_multi handles
        // and close their file descriptors. Responses that have already been
        // consumed (getContent/getStatusCode called) don't need their client.
        if (\count($this->activeClients) > self::MAX_ACTIVE_CLIENTS) {
            // Keep only the most recent half
            $this->activeClients = \array_slice($this->activeClients, -25, null, true);
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // For streaming, we need the originating client.
        // If we have a single response, use its client. Otherwise fall back.
        if ($responses instanceof ResponseInterface) {
            $id = spl_object_id($responses);
            if (isset($this->activeClients[$id])) {
                $client = $this->activeClients[$id];
                // Clean up the reference — we're about to consume the response
                unset($this->activeClients[$id]);
                return $client->stream($responses, $timeout);
            }
        }

        // Fallback: create a temporary client for streaming
        $client = new CurlHttpClient($this->baseOptions);
        return $client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        // Merge new options into base options
        $ref = new \ReflectionProperty($clone, 'baseOptions');
        $ref->setValue($clone, array_merge($this->baseOptions, $options));
        return $clone;
    }
}
