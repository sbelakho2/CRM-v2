<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * HttpClient decorator that routes each request through a different free proxy
 * via ProxyRotator. Creates a fresh CurlHttpClient per request to ensure each
 * request uses a distinct proxy (Symfony's CurlHttpClient pools connections
 * and ignores per-request proxy options).
 *
 * Used when ENABLE_PROXY_ROTATION=true and no paid SCRAPER_PROXY_URL is set.
 * Free proxies are fetched from GitHub-hosted public proxy lists.
 *
 * Fallback: if no proxy is available, connects directly (same as no proxy).
 */
final class FreeProxyHttpClient implements HttpClientInterface
{
    /** Track created clients so we can stream their responses */
    private array $activeClients = [];

    public function __construct(
        private readonly array $baseOptions,
        private readonly ProxyRotator $proxyRotator,
        private readonly LoggerInterface $logger,
    ) {
        // Pre-load proxy list eagerly so first request doesn't stall
        $this->proxyRotator->refreshProxyList();
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $proxy = $this->proxyRotator->getNextProxy();

        $clientOptions = $this->baseOptions;
        if ($proxy) {
            $clientOptions['proxy'] = $proxy;
            $clientOptions['timeout'] = 15; // shorter timeout for proxy requests
        }

        // New CurlHttpClient per request — same pattern as RotatingProxyHttpClient
        $client = new CurlHttpClient($clientOptions);

        $this->logger->debug('FreeProxyHttp: routing request', [
            'has_proxy' => $proxy !== null,
            'target' => mb_substr($url, 0, 80),
        ]);

        $response = $client->request($method, $url, $options);
        $this->activeClients[spl_object_id($response)] = [
            'client' => $client,
            'proxy' => $proxy,
        ];

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $id = spl_object_id($responses);
            if (isset($this->activeClients[$id])) {
                return $this->activeClients[$id]['client']->stream($responses, $timeout);
            }
        }

        // Fallback: create a temporary client for streaming
        $client = new CurlHttpClient($this->baseOptions);
        return $client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        return $clone;
    }

    /**
     * Report proxy success/failure after response is consumed.
     * Call from ScrapingSearchProvider after engine success/failure.
     */
    public function reportProxyResult(ResponseInterface $response, bool $success, string $reason = ''): void
    {
        $id = spl_object_id($response);
        if (!isset($this->activeClients[$id]['proxy'])) {
            return;
        }

        $proxy = $this->activeClients[$id]['proxy'];
        if ($proxy) {
            if ($success) {
                $this->proxyRotator->reportSuccess($proxy);
            } else {
                $this->proxyRotator->reportFailure($proxy, $reason);
            }
        }

        unset($this->activeClients[$id]);
    }

    /**
     * Get proxy pool stats for monitoring.
     */
    public function getProxyStats(): array
    {
        return $this->proxyRotator->getStats();
    }
}
