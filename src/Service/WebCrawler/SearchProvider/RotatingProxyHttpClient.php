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

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // For streaming, we need the originating client.
        // If we have a single response, use its client. Otherwise fall back.
        if ($responses instanceof ResponseInterface) {
            $id = spl_object_id($responses);
            if (isset($this->activeClients[$id])) {
                return $this->activeClients[$id]->stream($responses, $timeout);
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
