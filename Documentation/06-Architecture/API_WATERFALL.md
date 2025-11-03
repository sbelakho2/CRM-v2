# API Waterfall Strategy - Quote Co-Pilot

**Version:** 1.0  
**Last Updated:** October 29, 2025  
**Service:** `QuoteCoPilotService`

---

## Overview

The API Waterfall strategy is the core pricing intelligence mechanism in Quote Co-Pilot. It queries multiple supplier APIs in sequence until a price is found for each component in the BOM (Bill of Materials).

### Objectives
1. **Maximize Coverage:** Find prices for as many components as possible
2. **Minimize Cost:** Prefer cheaper suppliers when quality is equivalent
3. **Optimize Delivery:** Balance price with lead time for time-sensitive orders
4. **Quantity Intelligence:** Recognize volume pricing and recommend optimal order quantities

---

## Waterfall Sequence

```
User Upload BOM
    ↓
Parse BOM (CSV/Excel/JSON)
    ↓
For each line item:
    ↓
    1. Mouser API ────→ Found? → Return price breaks + stock
    ↓ Not Found
    2. DigiKey API ───→ Found? → Return price breaks + stock
    ↓ Not Found
    3. Nexar API ─────→ Found? → Return aggregated offers
    ↓ Not Found
    4. Alibaba API ───→ Found? → Return price ranges + delivery
    ↓ Not Found
    5. Internal Pricebook → Found? → Return historical price
    ↓ Not Found
    6. Imputation ────→ Estimate based on category/package
```

### API Priority Rationale

**Why Mouser First?**
- Most comprehensive electronics inventory
- Real-time stock availability
- Reliable lead times
- Excellent API documentation

**Why DigiKey Second?**
- Similar coverage to Mouser
- Competitive pricing
- Strong delivery performance
- Backup for Mouser stock-outs

**Why Nexar Third?**
- Aggregates multiple distributors
- Shows market pricing range
- Useful for bulk/obsolete parts
- Requires API key (optional)

**Why Alibaba Fourth?**
- Best for high-volume orders (MOQ 100+)
- Factory-direct pricing
- Long lead times acceptable for strategic buys
- Web scraping fallback if no API access

**Why Internal Pricebook Fifth?**
- Historical pricing data
- Previous negotiations
- Approved suppliers
- No API dependency

**Why Imputation Last?**
- No real supplier data
- Statistical estimation only
- Flag for operator review
- Better than "no price"

---

## Price Break Logic

### Concept
Suppliers offer volume discounts. For example:
```
Qty 1-99:    $0.50/unit
Qty 100-499: $0.45/unit
Qty 500+:    $0.40/unit
```

If the customer requests 250 units, the system should:
1. Recognize the qty=100-499 tier applies
2. Return $0.45/unit
3. Suggest ordering 500 to save $0.05/unit (11% discount)

### Implementation

**Method:** `selectPriceForQuantity(array $priceBreaks, int $requestedQty): float`

**Algorithm:**
```php
// Sort price breaks by quantity descending
usort($priceBreaks, fn($a, $b) => $b['qty'] <=> $a['qty']);

// Find the highest break where requestedQty >= break qty
foreach ($priceBreaks as $break) {
    if ($requestedQty >= $break['qty']) {
        return $break['price'];
    }
}

// If no break matches, return the lowest qty break (qty=1 price)
return end($priceBreaks)['price'];
```

**Example:**
```php
$priceBreaks = [
    ['qty' => 1, 'price' => 0.50],
    ['qty' => 100, 'price' => 0.45],
    ['qty' => 500, 'price' => 0.40]
];

selectPriceForQuantity($priceBreaks, 250);
// Returns: 0.45 (matches qty=100 tier)

selectPriceForQuantity($priceBreaks, 75);
// Returns: 0.50 (matches qty=1 tier)

selectPriceForQuantity($priceBreaks, 1000);
// Returns: 0.40 (matches qty=500 tier)
```

---

## Delivery Time Calculation

### Stock-Aware Lead Time

**Method:** `calculateLeadTime(int $requestedQty, array $availability): int`

**Scenarios:**

#### 1. Fully In-Stock
```php
Requested: 100 units
In Stock: 500 units
Factory Lead Time: 14 days

→ Lead Time: 3 days (ship from warehouse)
```

#### 2. Partial Stock
```php
Requested: 300 units
In Stock: 150 units
Factory Lead Time: 14 days

→ Lead Time: 7 + 14 = 21 days
   (7 days to ship partial + 14 days to manufacture remaining)
```

#### 3. No Stock (Factory Order)
```php
Requested: 500 units
In Stock: 0 units
Factory Lead Time: 84 days

→ Lead Time: 84 days (full factory production)
```

### Algorithm
```php
if ($requestedQty <= $inStock) {
    // Fully in-stock: quick ship
    return 3; // days
} elseif ($inStock > 0) {
    // Partial stock: staggered delivery
    return 7 + $factoryLeadTime;
} else {
    // No stock: factory production
    return $factoryLeadTime;
}
```

---

## Supplier-Specific Parsing

### Mouser API

**Endpoint:** `https://api.mouser.com/api/v1/search/partnumber`

**Request:**
```json
{
  "SearchByPartRequest": {
    "mouserPartNumber": "NE555P",
    "partSearchOptions": "string"
  }
}
```

**Response (Relevant Fields):**
```json
{
  "SearchResults": {
    "Parts": [
      {
        "ManufacturerPartNumber": "NE555P",
        "MouserPartNumber": "595-NE555P",
        "PriceBreaks": [
          {"Quantity": 1, "Price": "$0.50", "Currency": "USD"},
          {"Quantity": 100, "Price": "$0.45", "Currency": "USD"},
          {"Quantity": 500, "Price": "$0.40", "Currency": "USD"}
        ],
        "Availability": "500 In Stock",
        "FactoryLeadDays": 14,
        "DataSheetUrl": "https://..."
      }
    ]
  }
}
```

**Parsing Logic:**
```php
$priceBreaks = [];
foreach ($response['SearchResults']['Parts'][0]['PriceBreaks'] as $pb) {
    $priceBreaks[] = [
        'qty' => (int) $pb['Quantity'],
        'price' => (float) str_replace(['$', ','], '', $pb['Price'])
    ];
}

// Parse stock
preg_match('/(\d+)/', $response['...']['Availability'], $matches);
$inStock = (int) ($matches[1] ?? 0);

$factoryLeadTime = $response['...']['FactoryLeadDays'] ?? 14;
```

### DigiKey API

**Endpoint:** `https://api.digikey.com/products/v4/search/{partNumber}/productdetails`

**Response (Relevant Fields):**
```json
{
  "ManufacturerPartNumber": "NE555P",
  "DigiKeyPartNumber": "NE555P-ND",
  "StandardPricing": [
    {"BreakQuantity": 1, "UnitPrice": 0.52},
    {"BreakQuantity": 100, "UnitPrice": 0.47},
    {"BreakQuantity": 500, "UnitPrice": 0.42}
  ],
  "QuantityAvailable": 12000,
  "Manufacturer": {
    "StandardLeadTime": 84
  }
}
```

**Parsing Logic:**
```php
$priceBreaks = [];
foreach ($response['StandardPricing'] as $pricing) {
    $priceBreaks[] = [
        'qty' => $pricing['BreakQuantity'],
        'price' => $pricing['UnitPrice']
    ];
}

$inStock = $response['QuantityAvailable'] ?? 0;
$factoryLeadTime = $response['Manufacturer']['StandardLeadTime'] ?? 84;
```

### Nexar API

**Endpoint:** `https://api.nexar.com/graphql` (GraphQL)

**Query:**
```graphql
query Search($q: String!) {
  supSearch(q: $q, limit: 10) {
    results {
      part {
        mpn
        manufacturer {
          name
        }
      }
      sellers {
        company {
          name
        }
        offers {
          inventoryLevel
          prices {
            quantity
            price
            currency
          }
        }
      }
    }
  }
}
```

**Response:**
```json
{
  "data": {
    "supSearch": {
      "results": [
        {
          "part": {
            "mpn": "NE555P",
            "manufacturer": {"name": "Texas Instruments"}
          },
          "sellers": [
            {
              "company": {"name": "Arrow"},
              "offers": [
                {
                  "inventoryLevel": 5000,
                  "prices": [
                    {"quantity": 1, "price": 0.48, "currency": "USD"},
                    {"quantity": 100, "price": 0.43, "currency": "USD"}
                  ]
                }
              ]
            },
            {
              "company": {"name": "Avnet"},
              "offers": [...]
            }
          ]
        }
      ]
    }
  }
}
```

**Parsing Logic (Multi-Distributor Aggregation):**
```php
// Method: selectBestNexarOffer(array $offers, int $requestedQty): array

$bestOffer = null;
$bestScore = PHP_INT_MAX;

foreach ($sellers as $seller) {
    foreach ($seller['offers'] as $offer) {
        $unitPrice = selectPriceForQuantity($offer['prices'], $requestedQty);
        $leadTime = calculateLeadTime($requestedQty, [
            'inStock' => $offer['inventoryLevel'],
            'factoryLeadTime' => 84 // Default if not provided
        ]);
        
        // Scoring: Lower is better
        $score = ($unitPrice * 1000) + ($leadTime * 0.1);
        
        if ($score < $bestScore) {
            $bestScore = $score;
            $bestOffer = [
                'distributor' => $seller['company']['name'],
                'unitPrice' => $unitPrice,
                'priceBreaks' => $offer['prices'],
                'leadTime' => $leadTime,
                'inStock' => $offer['inventoryLevel']
            ];
        }
    }
}

return $bestOffer;
```

### Alibaba API

**Note:** Alibaba does not have a public API. Parsing requires web scraping or RFQ submission.

**Typical Response (Scraped Data):**
```json
{
  "productTitle": "NE555 Timer IC",
  "supplier": "Shenzhen ABC Electronics",
  "priceRanges": [
    {"minQty": 100, "maxQty": 499, "price": "$0.30"},
    {"minQty": 500, "maxQty": 999, "price": "$0.25"},
    {"minQty": 1000, "price": "$0.20"}
  ],
  "moq": 100,
  "deliveryTime": "15-25 days",
  "shippingFrom": "Guangdong, China"
}
```

**Parsing Logic:**
```php
// Method: parseAlibabaDeliveryTime(string $deliveryTimeStr): int

// Examples: "15-25 days", "3-5 weeks", "2-3 months"
if (preg_match('/(\d+)-(\d+)\s*(days?|weeks?|months?)/i', $deliveryTimeStr, $matches)) {
    $max = (int) $matches[2];
    $unit = strtolower($matches[3]);
    
    if (str_contains($unit, 'week')) {
        return $max * 7;
    } elseif (str_contains($unit, 'month')) {
        return $max * 30;
    } else {
        return $max; // days
    }
}

return 30; // Default: 30 days if parsing fails
```

---

## Coverage Calculation

**Definition:** Percentage of BOM lines with a price found.

**Formula:**
```
Coverage = (Lines with Price / Total Lines) × 100%
```

**Example:**
```
Total BOM Lines: 150
Lines with Price: 142
Coverage: 94.67%
```

**Thresholds:**
- **90-100%:** Excellent - Auto-publish quote
- **75-89%:** Good - Operator review recommended
- **50-74%:** Fair - Manual sourcing required
- **<50%:** Poor - Project not viable for automation

**Implementation:**
```php
public function calculateCoverage(array $bomResults): float
{
    $totalLines = count($bomResults);
    if ($totalLines === 0) {
        return 0.0;
    }
    
    $linesWithPrice = count(array_filter($bomResults, fn($line) => $line['price'] > 0));
    
    return ($linesWithPrice / $totalLines) * 100;
}
```

---

## Error Handling

### API Timeout
```php
try {
    $response = $httpClient->request('GET', $apiUrl, ['timeout' => 5]);
} catch (TransportExceptionInterface $e) {
    $this->logger->warning('Mouser API timeout', [
        'mpn' => $mpn,
        'error' => $e->getMessage()
    ]);
    return null; // Fall through to next API
}
```

### Invalid Response
```php
if (!isset($response['SearchResults']['Parts'][0])) {
    $this->logger->info('Mouser: Part not found', ['mpn' => $mpn]);
    return null;
}
```

### Rate Limiting
```php
if ($response->getStatusCode() === 429) {
    $retryAfter = (int) ($response->getHeaders()['Retry-After'][0] ?? 60);
    sleep($retryAfter);
    // Retry request
}
```

### MOQ Not Met
```php
if ($requestedQty < $moq) {
    return [
        'status' => 'MOQ_NOT_MET',
        'moq' => $moq,
        'requestedQty' => $requestedQty,
        'message' => "Minimum order quantity is {$moq} units"
    ];
}
```

---

## Caching Strategy

### Cache Key Structure
```
quote_copilot:api:{supplier}:{mpn}:{qty}
```

**Example:**
```
quote_copilot:api:mouser:NE555P:100
```

### TTL (Time to Live)
- **Mouser/DigiKey:** 24 hours (prices change daily)
- **Nexar:** 12 hours (real-time aggregator)
- **Alibaba:** 7 days (slower price updates)
- **Internal Pricebook:** No expiry (historical data)

### Implementation
```php
$cacheKey = sprintf('quote_copilot:api:%s:%s:%d', $supplier, $mpn, $qty);

// Try cache first
if ($cachedResult = $this->cache->get($cacheKey)) {
    return $cachedResult;
}

// Call API
$result = $this->callSupplierApi($supplier, $mpn, $qty);

// Cache result
$ttl = match($supplier) {
    'mouser', 'digikey' => 86400,  // 24 hours
    'nexar' => 43200,               // 12 hours
    'alibaba' => 604800,            // 7 days
    default => 3600                 // 1 hour
};

$this->cache->set($cacheKey, $result, $ttl);

return $result;
```

---

## Performance Optimization

### Parallel API Calls
For a BOM with 100 lines, calling APIs serially would take:
```
100 lines × 4 APIs × 2 seconds/call = 800 seconds (~13 minutes)
```

**Solution:** Symfony HttpClient async requests
```php
$promises = [];

foreach ($bomLines as $line) {
    $promises[] = $httpClient->request('GET', "https://api.mouser.com/...", [
        'query' => ['mpn' => $line['mpn']]
    ]);
}

// Wait for all responses
foreach ($httpClient->stream($promises) as $response => $chunk) {
    if ($chunk->isLast()) {
        $results[] = $response->toArray();
    }
}
```

**Result:** ~10 seconds for 100 lines (80× faster)

### Database Query Optimization
```php
// BAD: N+1 queries
foreach ($bomLines as $line) {
    $pricebook = $this->pricebookRepo->findByMpn($line['mpn']);
}

// GOOD: Single query
$mpns = array_column($bomLines, 'mpn');
$pricebookEntries = $this->pricebookRepo->findByMpns($mpns);
$pricebookMap = [];
foreach ($pricebookEntries as $entry) {
    $pricebookMap[$entry->getMpn()] = $entry;
}
```

---

## Testing

### Unit Tests
```php
public function testSelectPriceForQuantity(): void
{
    $priceBreaks = [
        ['qty' => 1, 'price' => 0.50],
        ['qty' => 100, 'price' => 0.45],
        ['qty' => 500, 'price' => 0.40]
    ];
    
    $this->assertEquals(0.45, $service->selectPriceForQuantity($priceBreaks, 250));
    $this->assertEquals(0.50, $service->selectPriceForQuantity($priceBreaks, 75));
    $this->assertEquals(0.40, $service->selectPriceForQuantity($priceBreaks, 1000));
}

public function testCalculateLeadTime(): void
{
    // Fully in-stock
    $this->assertEquals(3, $service->calculateLeadTime(100, ['inStock' => 500, 'factoryLeadTime' => 14]));
    
    // Partial stock
    $this->assertEquals(21, $service->calculateLeadTime(300, ['inStock' => 150, 'factoryLeadTime' => 14]));
    
    // No stock
    $this->assertEquals(84, $service->calculateLeadTime(500, ['inStock' => 0, 'factoryLeadTime' => 84]));
}
```

### Integration Tests
```php
public function testWaterfallApis(): void
{
    $result = $this->service->waterfallApis('NE555P', 100, 'US');
    
    $this->assertArrayHasKey('supplier', $result);
    $this->assertArrayHasKey('unitPrice', $result);
    $this->assertArrayHasKey('priceBreaks', $result);
    $this->assertArrayHasKey('leadTimeDays', $result);
    $this->assertGreaterThan(0, $result['unitPrice']);
}
```

---

## Roadmap

### Phase 1 (Current)
- ✅ Mouser, DigiKey, Nexar, Alibaba APIs
- ✅ Price break logic
- ✅ Delivery time tracking
- ✅ Coverage calculation

### Phase 2 (Q1 2026)
- ⏳ LCSC API integration (China market)
- ⏳ Octopart API (alternative aggregator)
- ⏳ Machine learning price prediction
- ⏳ Historical price trend analysis

### Phase 3 (Q2 2026)
- ⏳ Real-time inventory monitoring
- ⏳ Automatic reordering based on stock alerts
- ⏳ Supplier performance scoring
- ⏳ Multi-currency support (EUR, GBP, CNY)

---

## Appendix: API Authentication

### Mouser
```php
$apiKey = $_ENV['MOUSER_API_KEY'];
$response = $httpClient->request('POST', 'https://api.mouser.com/api/v1/search/partnumber', [
    'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ],
    'query' => ['apiKey' => $apiKey],
    'json' => $requestBody
]);
```

### DigiKey
```php
$clientId = $_ENV['DIGIKEY_CLIENT_ID'];
$clientSecret = $_ENV['DIGIKEY_CLIENT_SECRET'];

// OAuth 2.0 flow
$tokenResponse = $httpClient->request('POST', 'https://api.digikey.com/v1/oauth2/token', [
    'body' => [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials'
    ]
]);

$accessToken = $tokenResponse->toArray()['access_token'];

// Use token in API calls
$response = $httpClient->request('GET', "https://api.digikey.com/products/v4/search/{$mpn}/productdetails", [
    'headers' => [
        'Authorization' => "Bearer {$accessToken}",
        'X-DIGIKEY-Client-Id' => $clientId
    ]
]);
```

### Nexar
```php
$clientId = $_ENV['NEXAR_CLIENT_ID'];
$clientSecret = $_ENV['NEXAR_CLIENT_SECRET'];

// OAuth 2.0 flow
$tokenResponse = $httpClient->request('POST', 'https://identity.nexar.com/connect/token', [
    'body' => [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'client_credentials',
        'scope' => 'supply.domain'
    ]
]);

$accessToken = $tokenResponse->toArray()['access_token'];

// GraphQL request
$response = $httpClient->request('POST', 'https://api.nexar.com/graphql', [
    'headers' => [
        'Authorization' => "Bearer {$accessToken}",
        'Content-Type' => 'application/json'
    ],
    'json' => [
        'query' => $graphqlQuery,
        'variables' => ['q' => $mpn]
    ]
]);
```

---

**Document End**
