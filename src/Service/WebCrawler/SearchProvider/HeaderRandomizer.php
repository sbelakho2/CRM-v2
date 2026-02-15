<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Randomizes HTTP headers to evade bot detection.
 *
 * Search engines detect bots by:
 *   - Consistent User-Agent strings
 *   - Missing or unusual headers
 *   - Lack of header variation across requests
 *   - TLS fingerprinting (harder to mitigate in PHP)
 *
 * This service provides:
 *   - Large pool of real browser User-Agents
 *   - Realistic Accept/Accept-Language headers
 *   - Referer spoofing (appear to come from Google)
 *   - DNT and other privacy headers
 *   - Header ordering randomization
 */
final class HeaderRandomizer
{
    /**
     * Real browser User-Agents (updated Feb 2026).
     * Mix of Chrome, Firefox, Safari, Edge on Windows, Mac, Linux.
     */
    private const USER_AGENTS = [
        // Chrome on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',

        // Chrome on Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',

        // Chrome on Linux
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Ubuntu; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',

        // Firefox on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Windows NT 11.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',

        // Firefox on Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.3; rv:123.0) Gecko/20100101 Firefox/123.0',

        // Firefox on Linux
        'Mozilla/5.0 (X11; Linux x86_64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (X11; Linux x86_64; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:123.0) Gecko/20100101 Firefox/123.0',

        // Safari on Mac
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',

        // Edge on Windows
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
        'Mozilla/5.0 (Windows NT 11.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0',

        // Brave (appears as Chrome)
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Brave/122',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Brave/122',

        // Opera
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 OPR/108.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 OPR/108.0.0.0',

        // Vivaldi
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Vivaldi/6.5',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Vivaldi/6.5',
    ];

    /**
     * Accept header variations.
     */
    private const ACCEPT_HEADERS = [
        'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
    ];

    /**
     * Accept-Language variations by target region.
     */
    private const ACCEPT_LANGUAGES = [
        'default' => [
            'en-US,en;q=0.9',
            'en-US,en;q=0.9,es;q=0.8',
            'en-GB,en;q=0.9,en-US;q=0.8',
            'en,en-US;q=0.9',
        ],
        'DE' => [
            'de-DE,de;q=0.9,en;q=0.8',
            'de,de-DE;q=0.9,en-US;q=0.8,en;q=0.7',
            'de-DE,de;q=0.9,en-GB;q=0.8,en;q=0.7',
            'de-AT,de;q=0.9,de-DE;q=0.8,en;q=0.7',
        ],
        'FR' => [
            'fr-FR,fr;q=0.9,en;q=0.8',
            'fr,fr-FR;q=0.9,en-US;q=0.8,en;q=0.7',
            'fr-FR,fr;q=0.9,en-GB;q=0.8,en;q=0.7',
            'fr-BE,fr;q=0.9,fr-FR;q=0.8,en;q=0.7',
        ],
        'ES' => [
            'es-ES,es;q=0.9,en;q=0.8',
            'es,es-ES;q=0.9,en-US;q=0.8,en;q=0.7',
            'es-MX,es;q=0.9,es-ES;q=0.8,en;q=0.7',
        ],
        'IT' => [
            'it-IT,it;q=0.9,en;q=0.8',
            'it,it-IT;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'NL' => [
            'nl-NL,nl;q=0.9,en;q=0.8',
            'nl,nl-NL;q=0.9,en-US;q=0.8,en;q=0.7',
            'nl-BE,nl;q=0.9,nl-NL;q=0.8,en;q=0.7',
        ],
        'PL' => [
            'pl-PL,pl;q=0.9,en;q=0.8',
            'pl,pl-PL;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'CZ' => [
            'cs-CZ,cs;q=0.9,en;q=0.8',
            'cs,cs-CZ;q=0.9,sk;q=0.8,en;q=0.7',
        ],
        'RU' => [
            'ru-RU,ru;q=0.9,en;q=0.8',
            'ru,ru-RU;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'JP' => [
            'ja-JP,ja;q=0.9,en;q=0.8',
            'ja,ja-JP;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'CN' => [
            'zh-CN,zh;q=0.9,en;q=0.8',
            'zh,zh-CN;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
        'KR' => [
            'ko-KR,ko;q=0.9,en;q=0.8',
            'ko,ko-KR;q=0.9,en-US;q=0.8,en;q=0.7',
        ],
    ];

    /**
     * Referer URLs to appear as organic search traffic.
     */
    private const REFERERS = [
        'https://www.google.com/',
        'https://www.google.de/',
        'https://www.google.co.uk/',
        'https://www.google.fr/',
        'https://www.bing.com/',
        'https://duckduckgo.com/',
        'https://search.yahoo.com/',
        '', // Direct visit (no referer)
    ];

    /**
     * Get randomized headers for a request.
     *
     * @param string|null $region Target region for Accept-Language
     * @param string|null $targetUrl Target URL (for appropriate Referer)
     * @return array<string, string> Headers array
     */
    public function getRandomHeaders(?string $region = null, ?string $targetUrl = null): array
    {
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        $accept = self::ACCEPT_HEADERS[array_rand(self::ACCEPT_HEADERS)];

        // Get language preferences for region
        $languages = self::ACCEPT_LANGUAGES[$region] ?? self::ACCEPT_LANGUAGES['default'];
        $acceptLanguage = $languages[array_rand($languages)];

        // Get appropriate referer (avoid self-referencing)
        $referer = $this->getAppropriateReferer($targetUrl);

        $headers = [
            'User-Agent' => $userAgent,
            'Accept' => $accept,
            'Accept-Language' => $acceptLanguage,
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => $referer ? 'cross-site' : 'none',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
        ];

        // Add DNT (Do Not Track) randomly - ~30% of browsers send this
        if (random_int(1, 100) <= 30) {
            $headers['DNT'] = '1';
        }

        // Add Sec-CH-UA headers for Chrome-based browsers
        if (str_contains($userAgent, 'Chrome')) {
            $version = $this->extractChromeVersion($userAgent);
            $headers['Sec-CH-UA'] = '"Chromium";v="' . $version . '", "Google Chrome";v="' . $version . '", "Not-A.Brand";v="99"';
            $headers['Sec-CH-UA-Mobile'] = '?0';
            $headers['Sec-CH-UA-Platform'] = $this->extractPlatform($userAgent);
        }

        // Add referer if appropriate
        if ($referer) {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    /**
     * Get a random User-Agent string.
     */
    public function getRandomUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }

    /**
     * Get Accept-Language header for a region.
     */
    public function getAcceptLanguage(string $region): string
    {
        $languages = self::ACCEPT_LANGUAGES[$region] ?? self::ACCEPT_LANGUAGES['default'];
        return $languages[array_rand($languages)];
    }

    /**
     * Add jitter to request delay (anti-pattern detection).
     *
     * @param int $baseDelayMs Base delay in milliseconds
     * @param float $jitterFactor Jitter factor (0.0 to 1.0)
     * @return int Jittered delay in milliseconds
     */
    public function addJitter(int $baseDelayMs, float $jitterFactor = 0.5): int
    {
        $jitter = (int)($baseDelayMs * $jitterFactor);
        return $baseDelayMs + random_int(-$jitter, $jitter);
    }

    /**
     * Get appropriate referer for target URL.
     */
    private function getAppropriateReferer(?string $targetUrl): string
    {
        if (!$targetUrl) {
            return self::REFERERS[array_rand(self::REFERERS)];
        }

        // Don't send Google referer to Google properties
        $filteredReferers = array_filter(self::REFERERS, function (string $referer) use ($targetUrl) {
            if (empty($referer)) return true;
            $refererHost = parse_url($referer, PHP_URL_HOST);
            $targetHost = parse_url($targetUrl, PHP_URL_HOST);
            // Avoid same-domain referers
            return !str_contains($targetHost ?? '', str_replace('www.', '', $refererHost ?? ''));
        });

        return $filteredReferers ? $filteredReferers[array_rand($filteredReferers)] : '';
    }

    /**
     * Extract Chrome version from User-Agent.
     */
    private function extractChromeVersion(string $userAgent): string
    {
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $matches)) {
            return $matches[1];
        }
        return '122';
    }

    /**
     * Extract platform for Sec-CH-UA-Platform header.
     */
    private function extractPlatform(string $userAgent): string
    {
        if (str_contains($userAgent, 'Windows')) {
            return '"Windows"';
        }
        if (str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS')) {
            return '"macOS"';
        }
        if (str_contains($userAgent, 'Linux')) {
            return '"Linux"';
        }
        return '"Unknown"';
    }
}
