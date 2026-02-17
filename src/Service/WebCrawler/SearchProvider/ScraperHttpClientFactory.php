<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Factory that creates a proxy-aware HttpClient for search engine scrapers.
 *
 * When SCRAPER_PROXY_URL is configured in .env, all scraper HTTP traffic
 * routes through a residential proxy to avoid datacenter IP blocking.
 *
 * Without a proxy, scrapers connect directly (works from residential IPs,
 * fails from datacenter IPs like OVH/AWS/GCP).
 *
 * Usage in services.yaml:
 *   Bind the factory's createClient() output to all scraper services.
 *
 * Supported proxy providers:
 *   - Bright Data:  http://USERNAME:PASSWORD@brd.superproxy.io:33335
 *   - Oxylabs:      http://USERNAME:PASSWORD@pr.oxylabs.io:7777
 *   - IPRoyal:      http://USERNAME:PASSWORD@geo.iproyal.com:12321
 *   - SmartProxy:   http://USERNAME:PASSWORD@gate.smartproxy.com:10001
 */
final class ScraperHttpClientFactory
{
    private ?HttpClientInterface $client = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::SCRAPER_PROXY_URL)%')]
        private readonly ?string $proxyUrl = null,
    ) {}

    /**
     * Create (or return cached) proxy-aware HttpClient for scrapers.
     */
    public function createClient(): HttpClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $options = [
            'timeout' => 20,
            'max_redirects' => 5,
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ],
        ];

        if ($this->proxyUrl !== null && $this->proxyUrl !== '') {
            $options['proxy'] = $this->proxyUrl;

            // Mask credentials for logging
            $maskedUrl = preg_replace('#://([^:]+):([^@]+)@#', '://***:***@', $this->proxyUrl);
            $this->logger->info('ScraperHttpClientFactory: proxy enabled', [
                'proxy' => $maskedUrl,
            ]);
        } else {
            $this->logger->debug('ScraperHttpClientFactory: no proxy configured — direct connection');
        }

        $this->client = HttpClient::create($options);
        return $this->client;
    }
}
