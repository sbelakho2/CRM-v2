<?php

namespace App\Service\Integration;

use App\Service\PartMatchConfidenceCalculator;
use App\Service\ProxyRotationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Alibaba.com Web Crawler Client
 * 
 * Crawls Alibaba showroom pages to extract factory-direct pricing for
 * electronic components. Uses the publicly accessible showroom endpoint
 * (/showroom/{MPN}.html) which returns structured product listings
 * without requiring API credentials.
 * 
 * Best suited for:
 * - High-volume commodity components (MOQ 100+)
 * - Factory-direct pricing comparison
 * - Bulk discount discovery
 * - Supplier verification (Gold/Verified status, years, rating)
 * 
 * Anti-Bot Evasion:
 * - User-agent rotation (6 real browser UAs)
 * - Randomized human-like delays (2-5s between requests)
 * - Proxy rotation (when configured via ProxyRotationService)
 * - Full browser headers (Accept, Sec-Fetch-*, DNT, Referer)
 * - CAPTCHA detection with automatic retry/backoff
 * - Exponential backoff on 429 rate limiting
 * 
 * Extraction Strategies (cascading fallback):
 * 0. Embedded JSON (window._PAGE_DATA_) — structured, most reliable
 * 1. HTML5 data-attributes (data-product_id, ProductPrice component)
 * 2. Symfony DomCrawler structured parsing (product cards)
 * 3. Regex URL + price co-location (most resilient)
 * 
 * Data Extracted per listing:
 * - Product title, URL, product ID
 * - Price range (low-high USD)
 * - MOQ (minimum order quantity)
 * - Supplier: name, country, years on platform
 * - Verified/Gold supplier status
 * - Star rating and review count
 * - Dispatch time (3-day, 5-day)
 * - Certifications (CE, RoHS, FCC)
 * - Units sold, discount percentage
 * 
 * Caching: 7-day TTL — factory pricing is stable
 */
class AlibabaApiClient
{
    private const SHOWROOM_URL = 'https://www.alibaba.com/showroom/%s.html';
    private const MAX_ALTERNATIVES = 3;
    private const CACHE_TTL = 604800; // 7 days
    private const MIN_DELAY_US = 2000000;  // 2s minimum between requests
    private const MAX_DELAY_US = 5000000;  // 5s maximum
    private const REQUEST_TIMEOUT = 15;
    private const MAX_RETRIES = 2;

    // Real browser user-agents for rotation
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
    ];

    // Accept-Language variants for fingerprint diversity
    private const ACCEPT_LANGUAGES = [
        'en-US,en;q=0.9',
        'en-GB,en;q=0.9,en-US;q=0.8',
        'en-US,en;q=0.9,fr;q=0.8',
        'en,en-US;q=0.9,de;q=0.7',
    ];

    private float $lastRequestTime = 0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private ?ProxyRotationService $proxyRotation = null,
        private ?PartMatchConfidenceCalculator $confidenceCalculator = null
    ) {
        $this->confidenceCalculator ??= new PartMatchConfidenceCalculator();
    }

    // ========================================================================
    // Public API — matches MouserApiClient / DigiKeyApiClient interface
    // ========================================================================

    /**
     * Search for a part by manufacturer part number
     * 
     * Crawls the Alibaba showroom page for the given MPN and extracts
     * all matching product listings with pricing, MOQ, and supplier data.
     * Results are scored by relevance and supplier quality.
     * 
     * @param string $partNumber The MPN to search for
     * @param string|null $manufacturer Optional manufacturer name for scoring boost
     * @param string|null $description Optional description for confidence calculation
     * 
     * @return array|null Standard distributor result format with confidence scoring
     */
    public function searchByPartNumber(
        string $partNumber,
        ?string $manufacturer = null,
        ?string $description = null
    ): ?array {
        $cacheKey = 'alibaba_crawl_v3_' . md5($partNumber . ($manufacturer ?? ''));
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber, $manufacturer, $description) {
            $item->expiresAfter(self::CACHE_TTL);
            
            $crawlResult = $this->crawlShowroomPage($partNumber);
            
            if ($crawlResult === null || empty($crawlResult)) {
                $this->logger->debug('Alibaba crawl returned no products', [
                    'mpn' => $partNumber,
                ]);
                return null;
            }
            
            // Score and rank all crawled products
            $scored = $this->scoreAndRankProducts($crawlResult, $partNumber, $manufacturer);
            
            if (empty($scored)) {
                return null;
            }
            
            // Format the best match
            $selected = $this->formatCrawledProduct($scored[0], $partNumber);
            
            // Calculate confidence
            $confidence = $this->confidenceCalculator->calculateConfidence(
                $partNumber,
                $manufacturer,
                $description,
                $selected
            );
            
            // Alibaba gets a -10 penalty (not an authorized distributor)
            $confidence['score'] = max(0, $confidence['score'] - 10);
            if ($confidence['score'] < 70) {
                $confidence['level'] = 'MEDIUM';
            }
            $confidence['warnings'] = array_merge($confidence['warnings'] ?? [], [
                'Alibaba pricing is factory-direct, not from authorized distributor',
            ]);
            
            $selected['confidence'] = $confidence;
            
            // Build alternatives (next best matches)
            $alternatives = [];
            for ($i = 1; $i < min(count($scored), self::MAX_ALTERNATIVES + 1); $i++) {
                $alt = $this->formatCrawledProduct($scored[$i], $partNumber);
                $altConfidence = $this->confidenceCalculator->calculateConfidence(
                    $partNumber, $manufacturer, $description, $alt
                );
                $altConfidence['score'] = max(0, $altConfidence['score'] - 10);
                $alt['confidence'] = $altConfidence;
                $alternatives[] = $alt;
            }
            $selected['alternatives'] = $alternatives;
            $selected['lifecycle_warning'] = null;
            
            if ($confidence['requiresReview']) {
                $this->logger->warning('Alibaba crawl match requires review', [
                    'requested_mpn' => $partNumber,
                    'matched_title' => $selected['description'] ?? 'N/A',
                    'confidence_score' => $confidence['score'],
                    'products_found' => count($crawlResult),
                ]);
            }
            
            $this->logger->info('Alibaba crawl successful', [
                'mpn' => $partNumber,
                'products_found' => count($crawlResult),
                'best_price' => $selected['pricing'][0]['price'] ?? 'N/A',
                'best_supplier' => $selected['manufacturer'] ?? 'N/A',
            ]);
            
            return $selected;
        });
    }

    /**
     * Build Alibaba search URL for a part number (for user-facing links)
     */
    public function buildSearchUrl(string $partNumber): string
    {
        return sprintf(self::SHOWROOM_URL, urlencode($partNumber));
    }

    /**
     * Parse delivery time string to days
     * 
     * "15-25 days" → 25, "3-5 weeks" → 35, "1-2 months" → 60
     */
    public function parseDeliveryTime(string $deliveryTimeStr): int
    {
        if (preg_match('/(\d+)-(\d+)\s*days?/i', $deliveryTimeStr, $m)) {
            return (int) $m[2];
        } elseif (preg_match('/(\d+)\s*days?/i', $deliveryTimeStr, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/(\d+)-(\d+)\s*weeks?/i', $deliveryTimeStr, $m)) {
            return (int) $m[2] * 7;
        } elseif (preg_match('/(\d+)\s*weeks?/i', $deliveryTimeStr, $m)) {
            return (int) $m[1] * 7;
        }

        if (preg_match('/(\d+)-(\d+)\s*months?/i', $deliveryTimeStr, $m)) {
            return (int) $m[2] * 30;
        } elseif (preg_match('/(\d+)\s*months?/i', $deliveryTimeStr, $m)) {
            return (int) $m[1] * 30;
        }

        return 30;
    }

    // ========================================================================
    // Crawling Engine
    // ========================================================================

    /**
     * Crawl the Alibaba showroom page for a given MPN
     * 
     * The showroom endpoint (/showroom/{MPN}.html) is publicly accessible
     * and returns structured product listings. Unlike /trade/search which
     * aggressively blocks automated access with CAPTCHAs, showroom pages
     * serve full HTML to standard HTTP requests.
     * 
     * @return array[]|null Array of raw product data extracted from HTML
     */
    private function crawlShowroomPage(string $partNumber): ?array
    {
        $url = sprintf(self::SHOWROOM_URL, urlencode($partNumber));
        
        $html = $this->fetchWithRetry($url);
        
        if ($html === null) {
            return null;
        }
        
        return $this->parseShowroomHtml($html, $partNumber);
    }

    /**
     * Fetch a URL with retry, UA rotation, proxy support, and CAPTCHA detection
     */
    private function fetchWithRetry(string $url): ?string
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->respectRateLimit();
            
            $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
            $acceptLang = self::ACCEPT_LANGUAGES[array_rand(self::ACCEPT_LANGUAGES)];
            
            $options = [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => $acceptLang,
                    // Note: Do NOT set Accept-Encoding manually — Symfony HttpClient
                    // handles content-encoding negotiation and decompression automatically.
                    // Setting it explicitly bypasses auto-decompression and returns raw bytes.
                    'DNT' => '1',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Cache-Control' => 'max-age=0',
                    'Referer' => 'https://www.alibaba.com/',
                ],
                'timeout' => self::REQUEST_TIMEOUT,
                'max_redirects' => 3,
            ];
            
            // Proxy rotation support
            $proxy = $this->proxyRotation?->getNextProxy();
            if ($proxy) {
                $options['proxy'] = $proxy;
            }
            
            try {
                $response = $this->httpClient->request('GET', $url, $options);
                $statusCode = $response->getStatusCode();
                
                if ($statusCode === 200) {
                    $content = $response->getContent(false);
                    
                    // CAPTCHA detection
                    if ($this->isCaptchaPage($content)) {
                        $this->logger->warning('Alibaba CAPTCHA detected', [
                            'url' => $url,
                            'attempt' => $attempt + 1,
                            'proxy' => $proxy ? 'yes' : 'no',
                        ]);
                        
                        if ($proxy) {
                            $this->proxyRotation?->reportFailure($proxy);
                        }
                        
                        // Longer backoff before retry
                        usleep(rand(5000000, 10000000));
                        continue;
                    }
                    
                    if ($proxy) {
                        $this->proxyRotation?->reportSuccess($proxy);
                    }
                    
                    return $content;
                }
                
                if ($statusCode === 429) {
                    $this->logger->warning('Alibaba rate limited (429)', [
                        'url' => $url,
                        'attempt' => $attempt + 1,
                    ]);
                    usleep(rand(5000000, 15000000) * ($attempt + 1));
                    continue;
                }
                
                if ($statusCode >= 500) {
                    $this->logger->warning('Alibaba server error', [
                        'status' => $statusCode,
                        'attempt' => $attempt + 1,
                    ]);
                    usleep(rand(3000000, 6000000));
                    continue;
                }
                
                // 404 or other client error — don't retry
                $this->logger->debug('Alibaba returned non-success status', [
                    'status' => $statusCode,
                    'url' => $url,
                ]);
                return null;
                
            } catch (\Exception $e) {
                $this->logger->warning('Alibaba fetch exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
                
                if ($proxy) {
                    $this->proxyRotation?->reportFailure($proxy);
                }
                
                if ($attempt < self::MAX_RETRIES) {
                    usleep(rand(3000000, 8000000));
                }
            }
        }
        
        $this->logger->error('Alibaba crawl exhausted all retries', ['url' => $url]);
        return null;
    }

    /**
     * Detect CAPTCHA / bot verification challenge pages
     */
    private function isCaptchaPage(string $html): bool
    {
        $indicators = [
            'unusual traffic',
            'slide to verify',
            'captcha',
            'robot check',
            'security verification',
            '_____tmd_____',
            'baxia-dialog',
            'nc-container',
        ];
        
        $lower = strtolower($html);
        foreach ($indicators as $indicator) {
            if (str_contains($lower, $indicator)) {
                return true;
            }
        }
        
        // Very short HTML without showroom content = suspicious
        if (strlen($html) < 5000 && !str_contains($lower, 'showroom')) {
            return true;
        }
        
        return false;
    }

    // ========================================================================
    // HTML Parsing — 4 Cascading Extraction Strategies
    // ========================================================================

    /**
     * Parse showroom HTML and extract product listings
     * 
     * Uses 4 strategies in order of reliability:
     * 0. Embedded JSON (window._PAGE_DATA_) — structured, most reliable
     * 1. DOM data-product_id + data-component="ProductPrice" — HTML5 attributes
     * 2. DOM-based parsing via Symfony DomCrawler (product cards)
     * 3. Regex URL + price co-location (most resilient to layout changes)
     * 
     * @return array[] Parsed product data
     */
    private function parseShowroomHtml(string $html, string $partNumber): array
    {
        // Strategy 0 (PRIMARY): Extract from window._PAGE_DATA_ JSON blob
        $products = $this->extractProductsFromPageData($html, $partNumber);
        
        if (!empty($products)) {
            $this->logger->debug('Alibaba: extracted products via _PAGE_DATA_ JSON', [
                'count' => count($products),
            ]);
            return $products;
        }
        
        // Strategy 1: HTML5 data-attributes with ProductPrice component
        $products = $this->extractProductsFromDataAttributes($html, $partNumber);
        
        if (!empty($products)) {
            $this->logger->debug('Alibaba: extracted products via data-attributes', [
                'count' => count($products),
            ]);
            return $products;
        }
        
        // Strategy 2: Symfony DomCrawler
        $products = $this->extractProductsFromDom($html, $partNumber);
        
        if (!empty($products)) {
            $this->logger->debug('Alibaba: extracted products via DOM strategy', [
                'count' => count($products),
            ]);
            return $products;
        }
        
        // Strategy 3: Regex fallback
        $products = $this->extractProductsFromRegex($html, $partNumber);
        
        $this->logger->debug('Alibaba: extracted products via regex fallback', [
            'count' => count($products),
        ]);
        
        return $products;
    }

    // ========================================================================
    // Strategy 0: Embedded JSON Extraction (PRIMARY — most reliable)
    // ========================================================================

    /**
     * Extract products from window._PAGE_DATA_ embedded JSON
     * 
     * Alibaba showroom pages embed a comprehensive JSON blob as:
     *   window._PAGE_DATA_ = { ... };
     * 
     * The blob contains offerResultData.itemInfoList[] where each item has:
     * - offer.id — product ID
     * - offer.information.enPureTitle — English product title
     * - offer.tradePrice.price — formatted "US $0.30-$1.50"
     * - offer.tradePrice.minOrder — "1 piece"
     * - offer.lowerPrice / offer.upperPrice — "$0.30" / "$1.50"
     * - offer.company — bizType, expCountry, record.responseRate, transactionLevel
     * - offer.reviews — reviewScore, reviewCount, productScore
     * - offer.supplier — companyLogo, assessedSupplier
     * - offer.promotionInfoVO.quantityPrices[] — ladder pricing
     * - offer.features.crossReference — boolean
     * 
     * This is the most reliable strategy: structured, stable across page
     * redesigns, and contains data not visible in the HTML (ladder pricing,
     * response rates, transaction levels).
     */
    private function extractProductsFromPageData(string $html, string $partNumber): array
    {
        $pos = strpos($html, '_PAGE_DATA_');
        if ($pos === false) {
            return [];
        }
        
        $jsonStart = strpos($html, '{', $pos);
        if ($jsonStart === false) {
            return [];
        }
        
        // Find end of JSON using balanced brace counting
        $jsonStr = $this->extractBalancedJson($html, $jsonStart);
        if ($jsonStr === null) {
            return [];
        }
        
        $data = json_decode($jsonStr, true);
        if ($data === null) {
            $this->logger->debug('Alibaba: _PAGE_DATA_ JSON decode failed', [
                'error' => json_last_error_msg(),
                'json_length' => strlen($jsonStr),
            ]);
            return [];
        }
        
        $items = $data['offerResultData']['itemInfoList'] ?? [];
        if (empty($items)) {
            // Also check firstProductCachedData as a single-item fallback
            $firstProduct = $data['firstProductCachedData'] ?? null;
            if ($firstProduct && isset($firstProduct['offer'])) {
                $items = [['itemType' => $firstProduct['itemType'] ?? 'STANDARD', 'offer' => $firstProduct['offer']]];
            }
        }
        
        if (empty($items)) {
            $this->logger->debug('Alibaba: _PAGE_DATA_ has no itemInfoList');
            return [];
        }
        
        $products = [];
        foreach ($items as $item) {
            $offer = $item['offer'] ?? null;
            if ($offer === null) {
                continue;
            }
            
            $product = $this->parseOfferJson($offer);
            if ($product !== null) {
                $products[] = $product;
            }
        }
        
        return $products;
    }

    /**
     * Parse a single offer object from _PAGE_DATA_ JSON
     */
    private function parseOfferJson(array $offer): ?array
    {
        $productId = $offer['id'] ?? null;
        if (empty($productId)) {
            return null;
        }
        
        // Title
        $title = $offer['information']['enPureTitle'] 
            ?? $offer['puretitle'] 
            ?? $offer['information']['title'] 
            ?? null;
        if (empty($title)) {
            $title = 'Alibaba Product ' . $productId;
        }
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // URL
        $url = $offer['productUrl'] ?? $offer['detailUrl'] ?? null;
        if ($url) {
            // Normalize protocol-relative URLs
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            } elseif (str_starts_with($url, '/product-detail/')) {
                $url = 'https://www.alibaba.com' . $url;
            }
        } else {
            $url = 'https://www.alibaba.com/product-detail/_' . $productId . '.html';
        }
        
        // === Price Extraction ===
        $priceLow = null;
        $priceHigh = null;
        $currency = 'USD';
        $ladderPricing = [];
        
        // Primary: tradePrice (already in USD, formatted)
        $tradePrice = $offer['tradePrice'] ?? [];
        if (!empty($tradePrice['price'])) {
            // "US $0.30-$1.50"
            if (preg_match('/\$\s*([\d,.]+)\s*-\s*\$?\s*([\d,.]+)/', $tradePrice['price'], $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $this->parsePrice($pm[2]);
            } elseif (preg_match('/\$\s*([\d,.]+)/', $tradePrice['price'], $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $priceLow;
            }
        }
        
        // Fallback: lowerPrice/upperPrice fields (e.g., "$0.30")
        if ($priceLow === null) {
            $lower = $offer['lowerPrice'] ?? null;
            $upper = $offer['upperPrice'] ?? null;
            if ($lower) {
                $priceLow = $this->parsePrice(preg_replace('/[^\d.,]/', '', $lower));
            }
            if ($upper) {
                $priceHigh = $this->parsePrice(preg_replace('/[^\d.,]/', '', $upper));
            }
        }
        
        // Fallback: raw price field (local currency, numeric)
        if ($priceLow === null && !empty($offer['price'])) {
            $priceLow = (float) $offer['price'];
            $priceHigh = $priceLow;
            // Check if this is local currency via promotionInfoVO
            $promoInfo = $offer['promotionInfoVO'] ?? [];
            if (!empty($promoInfo['originalPriceFrom'])) {
                $priceLow = (float) $promoInfo['originalPriceFrom'];
            }
            if (!empty($promoInfo['originalPriceTo'])) {
                $priceHigh = (float) $promoInfo['originalPriceTo'];
            }
        }
        
        // Must have a valid price
        if ($priceLow === null || $priceLow <= 0) {
            return null;
        }
        
        // Ladder pricing from promotionInfoVO.quantityPrices
        $quantityPrices = $offer['promotionInfoVO']['quantityPrices'] ?? [];
        foreach ($quantityPrices as $qp) {
            if (isset($qp['price']) && isset($qp['quantityMin'])) {
                $ladderPricing[] = [
                    'quantity_min' => (int) $qp['quantityMin'],
                    'quantity_max' => isset($qp['quantityMax']) ? (int) $qp['quantityMax'] : null,
                    'price' => (float) $qp['price'],
                    'unit' => $qp['unit'] ?? 'piece',
                ];
            }
        }
        
        // === MOQ ===
        $moq = 1;
        $minOrder = $tradePrice['minOrder'] ?? '';
        if (preg_match('/(\d+)\s*(?:piece|set|unit|pcs?)/i', $minOrder, $moqMatch)) {
            $moq = (int) $moqMatch[1];
        }
        
        // === Supplier / Company Data ===
        $company = $offer['company'] ?? [];
        $supplier = $offer['supplier'] ?? [];
        
        $supplierYears = null;
        $transLevel = $company['transactionLevel'] ?? null;
        if ($transLevel !== null) {
            $supplierYears = (int) $transLevel;
        }
        
        $country = 'CN';
        $expCountry = $company['expCountry'] ?? '';
        if (!empty($expCountry)) {
            // "India/Italy" → take first
            $parts = preg_split('/[\/,]/', $expCountry);
            $country = trim($parts[0]);
        }
        
        $responseRate = null;
        if (!empty($company['record']['responseRate'])) {
            $responseRate = $company['record']['responseRate'];
        }
        
        $verified = (bool) ($supplier['assessedSupplier'] ?? false);
        
        // === Reviews ===
        $reviews = $offer['reviews'] ?? [];
        $rating = null;
        $reviewCount = null;
        if (!empty($reviews['reviewScore'])) {
            $rating = (float) $reviews['reviewScore'];
        } elseif (!empty($reviews['productScore'])) {
            $rating = (float) $reviews['productScore'];
        }
        if (isset($reviews['reviewCount'])) {
            $reviewCount = (int) $reviews['reviewCount'];
        }
        
        // === Image ===
        $image = $offer['image'] ?? [];
        $imageUrl = $image['mainImage'] ?? $image['bigImage'] ?? $image['extendImage'] ?? null;
        if ($imageUrl && str_starts_with($imageUrl, '//')) {
            $imageUrl = 'https:' . $imageUrl;
        }
        
        // === Certifications / Tags ===
        $certifications = [];
        $tagStr = $offer['tag']['tag'] ?? '';
        // Check product attributes for RoHS, CE, etc.
        $productAttrs = $offer['features']['productAttribute'] ?? [];
        foreach ($productAttrs as $attr) {
            $name = strtolower($attr['name'] ?? '');
            $value = $attr['value'] ?? '';
            if (str_contains($name, 'certification') || str_contains($name, 'rohs')) {
                if (preg_match('/RoHS|CE|FCC|UL|ISO/i', $value, $certMatch)) {
                    $certifications[] = $certMatch[0];
                }
            }
        }
        
        return [
            'product_id' => $productId,
            'title' => $title,
            'url' => $url,
            'price_low' => $priceLow,
            'price_high' => $priceHigh ?? $priceLow,
            'currency' => $currency,
            'moq' => max(1, $moq),
            'ladder_pricing' => $ladderPricing,
            'supplier_years' => $supplierYears,
            'country' => $country,
            'verified' => $verified,
            'rating' => $rating,
            'review_count' => $reviewCount,
            'response_rate' => $responseRate,
            'dispatch_days' => null,
            'units_sold' => null,
            'certifications' => $certifications,
            'discount_percent' => null,
            'delivery_estimate' => null,
            'image_url' => $imageUrl,
        ];
    }

    /**
     * Extract balanced JSON from HTML starting at a given brace position
     * 
     * Handles escaped quotes and nested objects/arrays correctly.
     */
    private function extractBalancedJson(string $html, int $startPos): ?string
    {
        $depth = 0;
        $inString = false;
        $len = strlen($html);
        
        for ($i = $startPos; $i < $len; $i++) {
            $c = $html[$i];
            
            // Handle escape sequences inside strings
            if ($c === '\\' && $inString) {
                $i++; // Skip next character
                continue;
            }
            
            if ($c === '"') {
                $inString = !$inString;
                continue;
            }
            
            if ($inString) {
                continue;
            }
            
            if ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($html, $startPos, $i - $startPos + 1);
                }
            }
        }
        
        return null; // Unbalanced
    }

    // ========================================================================
    // Strategy 1: Data-attribute extraction from HTML5 elements
    // ========================================================================

    /**
     * Extract products from data-product_id and data-component="ProductPrice"
     * 
     * Modern Alibaba pages use semantic HTML5 data-attributes:
     *   <div data-product_id="1601573588705" ...>
     *     <span data-component="ProductPrice">US $0.566-$1.044</span>
     *     <span data-component="ProductMoq">MOQ: 1</span>
     *   </div>
     */
    private function extractProductsFromDataAttributes(string $html, string $partNumber): array
    {
        $products = [];
        
        try {
            $crawler = new Crawler($html);
            
            $productDivs = $crawler->filter('[data-product_id]');
            if ($productDivs->count() === 0) {
                return [];
            }
            
            $seenIds = [];
            $productDivs->each(function (Crawler $div) use (&$products, &$seenIds) {
                $productId = $div->attr('data-product_id');
                if (empty($productId) || isset($seenIds[$productId])) {
                    return;
                }
                $seenIds[$productId] = true;
                
                $text = $div->text('');
                
                // Find product-detail link
                $url = null;
                $title = null;
                try {
                    $link = $div->filter('a[href*="product-detail"]')->first();
                    if ($link->count() > 0) {
                        $href = $link->attr('href') ?? '';
                        if (str_starts_with($href, '//')) {
                            $href = 'https:' . $href;
                        } elseif (str_starts_with($href, '/')) {
                            $href = 'https://www.alibaba.com' . $href;
                        }
                        $url = $href;
                        $linkText = trim($link->text(''));
                        if (strlen($linkText) > 10) {
                            $title = $linkText;
                        }
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
                
                if (!$url) {
                    $url = 'https://www.alibaba.com/product-detail/_' . $productId . '.html';
                }
                
                // Price from ProductPrice component or text
                $priceLow = null;
                $priceHigh = null;
                if (preg_match('/\$\s*([\d,.]+)\s*-\s*\$?\s*([\d,.]+)/', $text, $pm)) {
                    $priceLow = $this->parsePrice($pm[1]);
                    $priceHigh = $this->parsePrice($pm[2]);
                } elseif (preg_match('/\$\s*([\d,.]+)/', $text, $pm)) {
                    $priceLow = $this->parsePrice($pm[1]);
                    $priceHigh = $priceLow;
                }
                
                if (!$priceLow || $priceLow <= 0) {
                    return;
                }
                
                $moq = 1;
                if (preg_match('/MOQ:\s*(\d+)/i', $text, $mm)) {
                    $moq = (int) $mm[1];
                }
                
                $products[] = [
                    'product_id' => $productId,
                    'title' => $title ?? 'Product ' . $productId,
                    'url' => $url,
                    'price_low' => $priceLow,
                    'price_high' => $priceHigh,
                    'currency' => 'USD',
                    'moq' => max(1, $moq),
                    'ladder_pricing' => [],
                    'supplier_years' => null,
                    'country' => 'CN',
                    'verified' => (bool) preg_match('/Verified/i', $text),
                    'rating' => preg_match('/([\d.]+)\/5\.0/i', $text, $rm) ? (float) $rm[1] : null,
                    'review_count' => null,
                    'response_rate' => null,
                    'dispatch_days' => null,
                    'units_sold' => null,
                    'certifications' => [],
                    'discount_percent' => null,
                    'delivery_estimate' => null,
                    'image_url' => null,
                ];
            });
        } catch (\Exception $e) {
            $this->logger->debug('Alibaba data-attribute extraction failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $products;
    }

    // ========================================================================
    // Strategy 2: DOM-based extraction using Symfony DomCrawler (legacy)
    // ========================================================================
    private function extractProductsFromDom(string $html, string $partNumber): array
    {
        $products = [];
        
        try {
            $crawler = new Crawler($html);
            
            // Try known Alibaba product card CSS selectors
            $selectors = [
                '.organic-list .list-no-v2-outter',
                '.J-offer-wrapper',
                '.organic-gallery-offer-outter',
                '[data-content="productItem"]',
                '.organic-offer-wrapper',
                '.gallery-offer-outter',
                '.J-offer-card-wrapper',
            ];
            
            foreach ($selectors as $selector) {
                $cards = $crawler->filter($selector);
                if ($cards->count() > 0) {
                    $cards->each(function (Crawler $card) use (&$products, $partNumber) {
                        $product = $this->parseProductCard($card, $partNumber);
                        if ($product !== null) {
                            $products[] = $product;
                        }
                    });
                    break;
                }
            }
            
            // Fallback: find all product-detail links in the DOM
            if (empty($products)) {
                $links = $crawler->filter('a[href*="product-detail"]');
                $seenIds = [];
                
                $links->each(function (Crawler $link) use (&$products, &$seenIds, $partNumber) {
                    $href = $link->attr('href') ?? '';
                    if (preg_match('/_(\d{8,})\.html/', $href, $m)) {
                        $id = $m[1];
                        if (!isset($seenIds[$id])) {
                            $seenIds[$id] = true;
                            $text = trim($link->text(''));
                            
                            // Skip non-product links
                            if (strlen($text) < 10 || stripos($text, 'find similar') !== false) {
                                return;
                            }
                            
                            // Extract price from parent context
                            $parentText = '';
                            try {
                                $parent = $link->closest('div');
                                $parentText = $parent ? $parent->text('') : '';
                            } catch (\Exception $e) {
                                // closest() may not be available in all DomCrawler versions
                            }
                            
                            $priceLow = null;
                            $priceHigh = null;
                            if (preg_match('/\$\s*([\d,.]+)\s*-\s*\$?\s*([\d,.]+)/', $parentText, $pm)) {
                                $priceLow = $this->parsePrice($pm[1]);
                                $priceHigh = $this->parsePrice($pm[2]);
                            } elseif (preg_match('/\$\s*([\d,.]+)/', $parentText, $pm)) {
                                $priceLow = $this->parsePrice($pm[1]);
                                $priceHigh = $priceLow;
                            }
                            
                            $moq = 1;
                            if (preg_match('/MOQ:\s*(\d+)/i', $parentText, $mm)) {
                                $moq = (int) $mm[1];
                            }
                            
                            if ($priceLow && $priceLow > 0) {
                                $products[] = [
                                    'product_id' => $id,
                                    'title' => $text,
                                    'url' => $href,
                                    'price_low' => $priceLow,
                                    'price_high' => $priceHigh,
                                    'currency' => 'USD',
                                    'moq' => max(1, $moq),
                                    'supplier_years' => null,
                                    'country' => 'CN',
                                    'verified' => (bool) preg_match('/Verified/i', $parentText),
                                    'rating' => null,
                                    'review_count' => null,
                                    'dispatch_days' => null,
                                    'units_sold' => null,
                                    'certifications' => [],
                                    'discount_percent' => null,
                                    'delivery_estimate' => null,
                                ];
                            }
                        }
                    }
                });
            }
        } catch (\Exception $e) {
            $this->logger->debug('Alibaba DOM parsing failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $products;
    }

    /**
     * Parse a single product card DOM element
     */
    private function parseProductCard(Crawler $card, string $partNumber): ?array
    {
        try {
            $titleLink = $card->filter('a[href*="product-detail"]')->first();
            if ($titleLink->count() === 0) {
                return null;
            }
            
            $title = trim($titleLink->text(''));
            $url = $titleLink->attr('href') ?? '';
            $productId = '';
            
            if (preg_match('/_(\d+)\.html/', $url, $m)) {
                $productId = $m[1];
            }
            
            if (empty($title) || empty($productId)) {
                return null;
            }
            
            $cardText = $card->text('');
            
            $priceLow = null;
            $priceHigh = null;
            if (preg_match('/\$\s*([\d,.]+)\s*-\s*\$?\s*([\d,.]+)/', $cardText, $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $this->parsePrice($pm[2]);
            } elseif (preg_match('/\$\s*([\d,.]+)/', $cardText, $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $priceLow;
            }
            
            if (!$priceLow || $priceLow <= 0) {
                return null;
            }
            
            return [
                'product_id' => $productId,
                'title' => $title,
                'url' => $url,
                'price_low' => $priceLow,
                'price_high' => $priceHigh,
                'currency' => 'USD',
                'moq' => preg_match('/MOQ:\s*(\d+)/i', $cardText, $mm) ? max(1, (int) $mm[1]) : 1,
                'supplier_years' => preg_match('/(\d+)\s*yrs?/i', $cardText, $ym) ? (int) $ym[1] : null,
                'country' => preg_match('/country\s*flag\s*([A-Z]{2})/i', $cardText, $cm) ? $cm[1] : 'CN',
                'verified' => (bool) preg_match('/Verified\s+Supplier/i', $cardText),
                'rating' => preg_match('/([\d.]+)\/5\.0/i', $cardText, $rm) ? (float) $rm[1] : null,
                'review_count' => preg_match('/[\d.]+\/5\.0\s*\((\d+)\)/i', $cardText, $rcm) ? (int) $rcm[1] : null,
                'dispatch_days' => preg_match('/(\d+)-day\s+dispatch/i', $cardText, $dm) ? (int) $dm[1] : null,
                'units_sold' => preg_match('/(\d+)\s+sold/i', $cardText, $sm) ? (int) $sm[1] : null,
                'certifications' => [],
                'discount_percent' => null,
                'delivery_estimate' => null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Strategy 3: Regex-based extraction fallback
     * 
     * Finds product URLs paired with price patterns in nearby text.
     * Most resilient to HTML structure changes.
     */
    private function extractProductsFromRegex(string $html, string $partNumber): array
    {
        $products = [];
        $normalizedMpn = strtolower(trim($partNumber));
        $normalizedMpnClean = str_replace(['-', '_', ' ', '.'], '', $normalizedMpn);
        
        $urlPattern = '#(?:https?:)?(?://www\.alibaba\.com)?/product-detail/([^"\'>\s]+?)_(\d{8,})\.html#';
        if (!preg_match_all($urlPattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return [];
        }
        
        $seenIds = [];
        foreach ($matches as $match) {
            $productId = $match[2][0];
            $offset = $match[0][1];
            
            if (isset($seenIds[$productId])) {
                continue;
            }
            $seenIds[$productId] = true;
            
            $slug = $match[1][0];
            $title = $this->slugToTitle($slug);
            $titleLower = strtolower($title);
            $titleClean = str_replace(['-', '_', ' ', '.'], '', $titleLower);
            
            // Filter: title must contain the MPN (exact or normalized)
            if (!str_contains($titleLower, $normalizedMpn) && !str_contains($titleClean, $normalizedMpnClean)) {
                continue;
            }
            
            // Search 700-char window around the URL for price
            $searchWindow = substr($html, max(0, $offset - 200), 700);
            
            $priceLow = null;
            $priceHigh = null;
            if (preg_match('/\$\s*([\d,.]+)\s*-\s*\$?\s*([\d,.]+)/', $searchWindow, $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $this->parsePrice($pm[2]);
            } elseif (preg_match('/\$\s*([\d,.]+)/', $searchWindow, $pm)) {
                $priceLow = $this->parsePrice($pm[1]);
                $priceHigh = $priceLow;
            }
            
            if (!$priceLow || $priceLow <= 0) {
                continue;
            }
            
            $moq = 1;
            if (preg_match('/MOQ:\s*(\d+)/i', $searchWindow, $mm)) {
                $moq = (int) $mm[1];
            }
            
            $products[] = [
                'product_id' => $productId,
                'title' => $title,
                'url' => 'https://www.alibaba.com/product-detail/' . $slug . '_' . $productId . '.html',
                'price_low' => $priceLow,
                'price_high' => $priceHigh,
                'currency' => 'USD',
                'moq' => max(1, $moq),
                'supplier_years' => preg_match('/(\d+)\s*yrs?/i', $searchWindow, $ym) ? (int) $ym[1] : null,
                'country' => preg_match('/country\s*flag\s*([A-Z]{2})/i', $searchWindow, $cm) ? $cm[1] : 'CN',
                'verified' => (bool) preg_match('/Verified/i', $searchWindow),
                'rating' => null,
                'review_count' => null,
                'dispatch_days' => null,
                'units_sold' => null,
                'certifications' => [],
                'discount_percent' => null,
                'delivery_estimate' => null,
            ];
        }
        
        return $products;
    }

    // ========================================================================
    // Product Scoring & Ranking
    // ========================================================================

    /**
     * Score and rank crawled products by relevance and supplier quality
     * 
     * Scoring breakdown:
     *   MPN in title:       +80 (exact), +60 (normalized), +40 (partial prefix)
     *   Manufacturer match: +20
     *   Verified supplier:  +25
     *   Supplier years:     +2/yr (max +20)
     *   Rating:             +rating×5 (max +25)
     *   Review count:       +15 (≥50), +8 (≥10)
     *   Units sold:         +15
     *   Fast dispatch:      +10
     *   Certifications:     +5 each
     *   Suspiciously cheap: -30 (< $0.05)
     *   Very high MOQ:      -5 (> 1000)
     * 
     * @return array[] Sorted descending by score
     */
    private function scoreAndRankProducts(array $products, string $partNumber, ?string $manufacturer): array
    {
        $normalizedMpn = strtolower(trim($partNumber));
        $normalizedMpnClean = str_replace(['-', '_', ' ', '.'], '', $normalizedMpn);
        $normalizedMfr = $manufacturer ? strtolower(trim($manufacturer)) : null;
        
        $scored = [];
        foreach ($products as $product) {
            $score = 0;
            $titleLower = strtolower($product['title'] ?? '');
            $titleClean = str_replace(['-', '_', ' ', '.'], '', $titleLower);
            
            // MPN match in title
            if (str_contains($titleLower, $normalizedMpn)) {
                $score += 80;
            } elseif (str_contains($titleClean, $normalizedMpnClean)) {
                $score += 60;
            } else {
                $prefixLen = (int)(strlen($normalizedMpn) * 0.7);
                if ($prefixLen > 3 && str_contains($titleLower, substr($normalizedMpn, 0, $prefixLen))) {
                    $score += 40;
                }
            }
            
            // Manufacturer match
            if ($normalizedMfr && str_contains($titleLower, $normalizedMfr)) {
                $score += 20;
            }
            
            // Verified supplier
            if ($product['verified'] ?? false) {
                $score += 25;
            }
            
            // Supplier years
            $years = $product['supplier_years'] ?? 0;
            $score += min(20, $years * 2);
            
            // Rating
            $rating = $product['rating'] ?? 0;
            if ($rating > 0) {
                $score += min(25, (int)($rating * 5));
            }
            
            // Review count
            $reviews = $product['review_count'] ?? 0;
            if ($reviews >= 50) {
                $score += 15;
            } elseif ($reviews >= 10) {
                $score += 8;
            }
            
            // Units sold (social proof)
            if (($product['units_sold'] ?? 0) > 0) {
                $score += 15;
            }
            
            // Fast dispatch
            if (($product['dispatch_days'] ?? null) !== null && $product['dispatch_days'] <= 5) {
                $score += 10;
            }
            
            // Certifications
            $score += count($product['certifications'] ?? []) * 5;
            
            // Penalty: suspiciously cheap (< $0.05 for ICs)
            $price = $product['price_low'] ?? 0;
            if ($price > 0 && $price < 0.05) {
                $score -= 30;
            }
            
            // Penalty: very high MOQ
            if (($product['moq'] ?? 1) > 1000) {
                $score -= 5;
            }
            
            $product['_score'] = $score;
            $scored[] = $product;
        }
        
        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
        
        return $scored;
    }

    // ========================================================================
    // Output Formatting
    // ========================================================================

    /**
     * Format a crawled product into the standard distributor result structure
     * 
     * Matches the return format of MouserApiClient / DigiKeyApiClient / NexarApiClient:
     * - mpn, manufacturer, description, datasheet
     * - pricing[] with quantity/price/currency breaks
     * - stock, leadtime_days, moq
     * - lifecycle, rohs, category
     * - image_url, product_url
     * - confidence (added by caller)
     * - alternatives[] (added by caller)
     */
    private function formatCrawledProduct(array $product, string $requestedMpn): array
    {
        $priceLow = $product['price_low'] ?? 0;
        $priceHigh = $product['price_high'] ?? $priceLow;
        $moq = max(1, $product['moq'] ?? 1);
        $currency = $product['currency'] ?? 'USD';
        
        // Build price breaks from ladder pricing or low/high range
        $pricing = [];
        $ladderPricing = $product['ladder_pricing'] ?? [];
        
        if (!empty($ladderPricing)) {
            // Use real ladder pricing from JSON (most accurate)
            foreach ($ladderPricing as $tier) {
                $pricing[] = [
                    'quantity' => $tier['quantity_min'],
                    'price' => $tier['price'],
                    'currency' => $currency,
                ];
            }
        } elseif ($priceLow > 0) {
            if ($priceHigh > $priceLow) {
                // High price at MOQ, graduated to low price at bulk
                $pricing[] = ['quantity' => $moq, 'price' => $priceHigh, 'currency' => $currency];
                
                // Interpolated middle break
                $midQty = max($moq * 10, 100);
                $midPrice = round(($priceLow + $priceHigh) / 2, 4);
                $pricing[] = ['quantity' => $midQty, 'price' => $midPrice, 'currency' => $currency];
                
                // Bulk price
                $bulkQty = max($moq * 100, 1000);
                $pricing[] = ['quantity' => $bulkQty, 'price' => $priceLow, 'currency' => $currency];
            } else {
                $pricing[] = ['quantity' => $moq, 'price' => $priceLow, 'currency' => $currency];
            }
        }
        
        // Lead time from dispatch days or default
        $leadTimeDays = 30;
        if ($product['dispatch_days'] !== null) {
            $leadTimeDays = $product['dispatch_days'];
        }
        
        // Build rich supplier description
        $supplierParts = [];
        if ($product['verified']) {
            $supplierParts[] = 'Verified Supplier';
        }
        $supplierParts[] = $product['country'] ?? 'CN';
        if ($product['supplier_years']) {
            $supplierParts[] = $product['supplier_years'] . ' yrs';
        }
        if ($product['rating']) {
            $ratingStr = $product['rating'] . '/5.0';
            if ($product['review_count']) {
                $ratingStr .= ' (' . $product['review_count'] . ' reviews)';
            }
            $supplierParts[] = $ratingStr;
        }
        $supplierInfo = implode(', ', $supplierParts);
        
        $rohs = in_array('RoHS', $product['certifications'] ?? []) ? 'Compliant' : null;
        
        return [
            'mpn' => $requestedMpn,
            'manufacturer' => $supplierInfo,
            'description' => $product['title'] ?? null,
            'datasheet' => null,
            'pricing' => $pricing,
            'stock' => 0, // Alibaba = factory-order, no real-time stock
            'leadtime_days' => $leadTimeDays,
            'lifecycle' => null,
            'rohs' => $rohs,
            'category' => null,
            'image_url' => $product['image_url'] ?? null,
            'product_url' => $product['url'] ?? $this->buildSearchUrl($requestedMpn),
            '_all_matches_count' => 1,
            'moq' => $moq,
            'pack_quantity' => null,
            'multiple_quantity' => null,
            // Alibaba-specific
            'supplier_type' => $product['verified'] ? 'Verified Supplier' : 'Standard',
            'trade_assurance' => $product['verified'],
            'shipping_from' => $product['country'] ?? 'China',
            // Crawler metadata (for debugging/auditing)
            '_crawl_data' => [
                'product_id' => $product['product_id'],
                'supplier_years' => $product['supplier_years'],
                'rating' => $product['rating'],
                'review_count' => $product['review_count'],
                'response_rate' => $product['response_rate'] ?? null,
                'units_sold' => $product['units_sold'],
                'dispatch_days' => $product['dispatch_days'],
                'certifications' => $product['certifications'],
                'ladder_pricing' => $product['ladder_pricing'] ?? [],
                'price_range' => ['low' => $product['price_low'], 'high' => $product['price_high']],
                'relevance_score' => $product['_score'] ?? null,
            ],
        ];
    }

    // ========================================================================
    // Utility Helpers
    // ========================================================================

    /**
     * Convert URL slug to human-readable title
     * 
     * "Original-STM32F405RGT6-VGT6-ZGT6" → "Original STM32F405RGT6 VGT6 ZGT6"
     */
    private function slugToTitle(string $slug): string
    {
        return str_replace('-', ' ', $slug);
    }

    /**
     * Parse price string to float
     * 
     * Handles: "1.50", "1,500.00", "0.30", "1,50" (European)
     */
    private function parsePrice(string $priceStr): float
    {
        $clean = preg_replace('/[^\d.,]/', '', $priceStr);
        
        // Thousands separator: "1,500.00"
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $clean)) {
            $clean = str_replace(',', '', $clean);
        }
        // European decimal: "1,50"
        elseif (preg_match('/^\d+,\d{1,2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        }
        
        return (float) $clean;
    }

    /**
     * Enforce rate limiting with randomized human-like delays
     */
    private function respectRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = ($now - $this->lastRequestTime) * 1000000;
        
        if ($this->lastRequestTime > 0 && $elapsed < self::MAX_DELAY_US) {
            $remainingDelay = max(self::MIN_DELAY_US, self::MAX_DELAY_US - (int) $elapsed);
            usleep(rand(self::MIN_DELAY_US, $remainingDelay));
        }
        
        $this->lastRequestTime = microtime(true);
    }
}
