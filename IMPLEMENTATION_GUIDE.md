# Implementation Guide - Priority Features

**Date**: November 22, 2025  
**Purpose**: Step-by-step implementation instructions for missing functionalities  
**Priority Order**: Based on business value and dependencies

---

## Quick Start - What to Implement First

### Phase 1: Foundation (Week 1-2)

1. ✅ **BOM Parsing** - Already implemented in `QuoteCoPilotService`
2. **Dataset Import - Tariff Rates** - Critical for Quote Estimator
3. **Dataset Import - Freight Tables** - Critical for Quote Estimator
4. **Quote PDF Generation** - Required for Quote Co-Pilot

### Phase 2: Quote Features (Week 3-4)

5. **Quote Estimator - Duty Calculation**
6. **Quote Estimator - Freight Pricing**
7. **Quote Co-Pilot - Email Integration**

### Phase 3: ABM & Automation (Week 5-6)

8. **ABM - IP Resolution**
9. **ABM - Dashboard Display**
10. **ABM - Playbook Engine**

---

## 1. Dataset Import - Tariff Rates

**File**: `src/Service/DatasetImportService.php`  
**Method**: `importTariffData()`  
**Priority**: 🔴 **Critical** (Required for Quote Estimator)

### Implementation Steps

```php
public function importTariffData(string $csvPath, string $signaturePath, string $description): array
{
    // Step 1: Verify signature
    $this->verifySignature($csvPath, $signaturePath);

    // Step 2: Generate UUID for this version
    $versionUuid = Uuid::v4()->toRfc4122();
    $sha256Hash = hash_file('sha256', $csvPath);

    // Step 3: Create DatasetVersion entity
    $version = new DatasetVersion();
    $version->setDatasetType('TARIFF_RATES');
    $version->setVersionUuid($versionUuid);
    $version->setSha256Hash($sha256Hash);
    $version->setImportedAt(new \DateTime());
    $version->setImportedBy('admin'); // TODO: Get from security context
    $version->setIsActive(false);

    if ($description) {
        $version->setMetadata(['description' => $description]);
    }

    $this->entityManager->persist($version);
    $this->entityManager->flush(); // Get ID before importing rows

    // Step 4: Parse CSV
    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        throw new \RuntimeException("Cannot open CSV file: $csvPath");
    }

    // Read header
    $headers = fgetcsv($handle);
    $recordsImported = 0;
    $errors = [];

    // Step 5: Import each row
    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) {
            continue; // Skip empty rows
        }

        try {
            $data = array_combine($headers, $row);

            // Create TariffRate entity
            $tariffRate = new TariffRate();
            $tariffRate->setHtsCode($data['hts_code']);
            $tariffRate->setDestinationCountry($data['destination_country']);
            $tariffRate->setDutyRate((float)$data['duty_rate']);
            $tariffRate->setDutyType($data['duty_type'] ?? 'AD_VALOREM');
            $tariffRate->setVatRate(isset($data['vat_rate']) ? (float)$data['vat_rate'] : null);
            $tariffRate->setAsof(new \DateTime($data['asof']));
            $tariffRate->setVersionUuid($versionUuid);
            $tariffRate->setImportedAt(new \DateTime());

            $this->entityManager->persist($tariffRate);
            $recordsImported++;

            // Batch flush every 100 rows for performance
            if ($recordsImported % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear(); // Clear memory
            }

        } catch (\Exception $e) {
            $errors[] = "Row $recordsImported: " . $e->getMessage();
        }
    }

    fclose($handle);

    // Final flush
    $this->entityManager->flush();

    // Step 6: Update version with record count
    $version->setRecordCount($recordsImported);
    $this->entityManager->flush();

    // Step 7: Activate this version (deactivate others)
    $this->activateVersion($versionUuid);

    return [
        'versionId' => $versionUuid,
        'recordsImported' => $recordsImported,
        'errors' => $errors,
        'datasetType' => 'TARIFF_RATES'
    ];
}
```

### Testing

Create test CSV file: `tests/fixtures/tariff_rates_sample.csv`

```csv
hts_code,destination_country,duty_rate,duty_type,vat_rate,asof
8473.30.51,US,0.0,AD_VALOREM,0.0,2025-01-01
8473.30.51,FR,2.5,AD_VALOREM,20.0,2025-01-01
8471.30.00,DE,0.0,AD_VALOREM,19.0,2025-01-01
```

Test command:

```php
$service = $container->get(DatasetImportService::class);
$result = $service->importTariffData(
    'tests/fixtures/tariff_rates_sample.csv',
    'tests/fixtures/tariff_rates_sample.csv.sha256',
    'Test Import'
);
```

---

## 2. Dataset Import - Freight Tables

**File**: `src/Service/DatasetImportService.php`  
**Method**: `importFreightData()`  
**Priority**: 🔴 **Critical**

### Implementation

```php
public function importFreightData(string $csvPath, string $signaturePath, string $description): array
{
    $this->verifySignature($csvPath, $signaturePath);

    $versionUuid = Uuid::v4()->toRfc4122();
    $sha256Hash = hash_file('sha256', $csvPath);

    $version = new DatasetVersion();
    $version->setDatasetType('FREIGHT_TABLES');
    $version->setVersionUuid($versionUuid);
    $version->setSha256Hash($sha256Hash);
    $version->setImportedAt(new \DateTime());
    $version->setIsActive(false);

    $this->entityManager->persist($version);
    $this->entityManager->flush();

    $handle = fopen($csvPath, 'r');
    $headers = fgetcsv($handle);
    $recordsImported = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) continue;

        $data = array_combine($headers, $row);

        $freight = new FreightTable();
        $freight->setOriginPort($data['origin_port']);
        $freight->setDestinationPort($data['destination_port']);
        $freight->setMode($data['mode']); // AIR, LCL, FCL
        $freight->setRatePerKg(isset($data['rate_per_kg']) ? (float)$data['rate_per_kg'] : null);
        $freight->setRatePerCbm(isset($data['rate_per_cbm']) ? (float)$data['rate_per_cbm'] : null);
        $freight->setFlatRate(isset($data['flat_rate']) ? (float)$data['flat_rate'] : null);
        $freight->setContainerType($data['container_type'] ?? null);
        $freight->setTransitDays(isset($data['transit_days']) ? (int)$data['transit_days'] : null);
        $freight->setVersionUuid($versionUuid);
        $freight->setAsof(new \DateTime($data['asof']));

        $this->entityManager->persist($freight);
        $recordsImported++;

        if ($recordsImported % 100 === 0) {
            $this->entityManager->flush();
        }
    }

    fclose($handle);
    $this->entityManager->flush();

    $version->setRecordCount($recordsImported);
    $this->entityManager->flush();

    $this->activateVersion($versionUuid);

    return [
        'versionId' => $versionUuid,
        'recordsImported' => $recordsImported,
        'datasetType' => 'FREIGHT_TABLES'
    ];
}
```

### Test CSV

```csv
origin_port,destination_port,mode,rate_per_kg,rate_per_cbm,flat_rate,container_type,transit_days,asof
CASABLANCA,JFK-NYC,AIR,4.50,,,20GP,5,2025-01-01
CASABLANCA,JFK-NYC,LCL,,45.00,,20GP,18,2025-01-01
CASABLANCA,JFK-NYC,FCL,,,2500.00,20GP,18,2025-01-01
TANGIER,ROTTERDAM,AIR,3.80,,,20GP,3,2025-01-01
```

---

## 3. Quote PDF Generation

**File**: `src/Service/UnifiedPdfGeneratorService.php`  
**Method**: `generateQuotePdf()`  
**Priority**: 🔴 **Critical**

### Install mPDF

```bash
composer require mpdf/mpdf
```

### Implementation

```php
public function generateQuotePdf(Quote $quote): string
{
    // Render Twig template
    $html = $this->twig->render('pdf/quote.html.twig', [
        'quote' => $quote,
        'generatedDate' => new \DateTime(),
    ]);

    // Configure mPDF
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4-L', // Landscape for BOM table
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10,
    ]);

    // Add header
    $mpdf->SetHeader('Quote ' . $quote->getQuoteNumber() . '|Date: {DATE j-m-Y}|Page {PAGENO}');

    // Add footer
    $mpdf->SetFooter('Starz Morocco PCBA|Confidential|Page {PAGENO} of {nbpg}');

    // Write HTML
    $mpdf->WriteHTML($html);

    // Return PDF content as string
    return $mpdf->Output('', 'S'); // 'S' = return as string
}
```

### Create Template

**File**: `templates/pdf/quote.html.twig`

```twig
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        h1 { font-size: 18pt; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #f5f0e8; padding: 8px; text-align: left; border: 1px solid #ddd; }
        td { padding: 6px; border: 1px solid #ddd; }
        .totals { text-align: right; font-weight: bold; }
        .header-info { margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="header-info">
        <h1>Quote {{ quote.quoteNumber }}</h1>
        <p><strong>Company:</strong> {{ quote.company.name }}</p>
        <p><strong>Date:</strong> {{ generatedDate|date('Y-m-d') }}</p>
        <p><strong>Coverage:</strong> {{ quote.coveragePercent }}%</p>
    </div>

    <h2>Bill of Materials</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>MPN</th>
                <th>Manufacturer</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Ext. Price</th>
                <th>Source</th>
            </tr>
        </thead>
        <tbody>
            {% for line in quote.bomLines %}
            <tr>
                <td>{{ line.lineNumber }}</td>
                <td>{{ line.mpn }}</td>
                <td>{{ line.manufacturer }}</td>
                <td>{{ line.description }}</td>
                <td>{{ line.quantity }}</td>
                <td>${{ line.unitPrice|number_format(2) }}</td>
                <td>${{ line.extendedPrice|number_format(2) }}</td>
                <td>{{ line.procurementSource }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>

    <div class="totals">
        <p><strong>Total Materials Cost:</strong> ${{ quote.totalCost|number_format(2) }}</p>
    </div>
</body>
</html>
```

---

## 4. Quote Estimator - Duty Calculation

**File**: `src/Service/DutyCalculationService.php`  
**Method**: `calculateDuty()`  
**Priority**: 🟡 **High**

### Implementation

```php
public function calculateDuty(
    string $htsCode,
    string $destinationCountry,
    float $goodsValue,
    string $originCountry = 'MA'
): array {
    // Step 1: Look up tariff rate
    $tariffRate = $this->tariffRateRepository->findOneBy([
        'htsCode' => $htsCode,
        'destinationCountry' => $destinationCountry,
    ]);

    if (!$tariffRate) {
        throw new \RuntimeException(
            "Tariff rate not found for HTS: $htsCode, Destination: $destinationCountry"
        );
    }

    // Step 2: Calculate duty based on type
    $dutyAmount = 0.0;

    if ($tariffRate->getDutyType() === 'AD_VALOREM') {
        // Percentage of value
        $dutyAmount = $goodsValue * ($tariffRate->getDutyRate() / 100);
    } elseif ($tariffRate->getDutyType() === 'SPECIFIC') {
        // Fixed amount per unit/weight
        // Requires quantity/weight parameter (not shown here)
        $dutyAmount = $tariffRate->getSpecificRate();
    }

    // Step 3: Calculate VAT (on goods value + duty)
    $vatBase = $goodsValue + $dutyAmount;
    $vatAmount = 0.0;

    if ($tariffRate->getVatRate()) {
        $vatAmount = $vatBase * ($tariffRate->getVatRate() / 100);
    }

    // Step 4: Check FTA eligibility
    $ftaEligible = $this->checkFtaEligibility($originCountry, $destinationCountry);
    $ftaRate = $ftaEligible ? 0.0 : $tariffRate->getDutyRate();
    $ftaSavings = $ftaEligible ? $dutyAmount : 0.0;

    return [
        'dutyRate' => $tariffRate->getDutyRate(),
        'dutyAmount' => round($dutyAmount, 2),
        'vatRate' => $tariffRate->getVatRate(),
        'vatAmount' => round($vatAmount, 2),
        'totalTaxes' => round($dutyAmount + $vatAmount, 2),
        'ftaEligible' => $ftaEligible,
        'ftaRate' => $ftaRate,
        'ftaSavings' => round($ftaSavings, 2),
    ];
}

private function checkFtaEligibility(string $origin, string $destination): bool
{
    // Morocco FTAs
    $ftas = [
        'MA' => ['US', 'FR', 'DE', 'ES', 'IT', 'NL', 'BE', 'TR', 'EG', 'JO', 'TN'],
    ];

    return isset($ftas[$origin]) && in_array($destination, $ftas[$origin]);
}
```

---

## 5. Quote Estimator - Freight Pricing

**File**: `src/Service/FreightPricingService.php`  
**Method**: `calculateFreight()`  
**Priority**: 🟡 **High**

### Implementation

```php
public function calculateFreight(
    string $originPort,
    string $destinationPort,
    float $weightKg,
    float $volumeM3,
    string $mode = 'AIR'
): array {
    // Step 1: Look up freight rate
    $freightRate = $this->freightTableRepository->findOneBy([
        'originPort' => $originPort,
        'destinationPort' => $destinationPort,
        'mode' => $mode,
    ]);

    if (!$freightRate) {
        throw new \RuntimeException(
            "Freight rate not found: $originPort -> $destinationPort ($mode)"
        );
    }

    // Step 2: Calculate based on mode
    $freightCost = 0.0;

    if ($mode === 'AIR') {
        // Air freight: charged by weight OR volumetric weight (whichever is higher)
        $volumetricWeight = $volumeM3 * 167; // 1 m³ = 167 kg for air
        $chargeableWeight = max($weightKg, $volumetricWeight);
        $freightCost = $chargeableWeight * $freightRate->getRatePerKg();

    } elseif ($mode === 'LCL') {
        // LCL: charged by volume (CBM)
        $freightCost = $volumeM3 * $freightRate->getRatePerCbm();

    } elseif ($mode === 'FCL') {
        // FCL: flat rate per container
        $freightCost = $freightRate->getFlatRate();
    }

    // Step 3: Add surcharges (fuel, security, etc.)
    $fuelSurcharge = $freightCost * 0.15; // 15% fuel surcharge
    $securitySurcharge = $mode === 'AIR' ? 50.00 : 25.00;

    $totalFreight = $freightCost + $fuelSurcharge + $securitySurcharge;

    return [
        'baseFreight' => round($freightCost, 2),
        'fuelSurcharge' => round($fuelSurcharge, 2),
        'securitySurcharge' => round($securitySurcharge, 2),
        'totalFreight' => round($totalFreight, 2),
        'transitDays' => $freightRate->getTransitDays(),
        'mode' => $mode,
    ];
}
```

---

## 6. ABM - IP Resolution

**File**: `src/Service/AbmResolverService.php`  
**Method**: `resolveIp()`  
**Priority**: 🟢 **Medium**

### Implementation

```php
public function resolveIp(string $ipAddress): ?AbmAccount
{
    // Step 1: Convert IP to long for range lookup
    $ipLong = ip2long($ipAddress);

    if ($ipLong === false) {
        return null; // Invalid IP
    }

    // Step 2: Find IP range in IpMap table
    $ipMap = $this->ipMapRepository->createQueryBuilder('i')
        ->where('i.ipStartInt <= :ipLong')
        ->andWhere('i.ipEndInt >= :ipLong')
        ->setParameter('ipLong', $ipLong)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();

    if (!$ipMap) {
        return null; // IP not in database
    }

    // Step 3: Filter residential ISPs
    $residentialIsps = ['Comcast', 'AT&T', 'Verizon', 'Spectrum', 'Orange', 'BT', 'Vodafone'];

    if (in_array($ipMap->getIsp(), $residentialIsps)) {
        return null; // Likely consumer, not B2B
    }

    // Step 4: Find or create AbmAccount
    $account = $this->abmAccountRepository->findOneBy([
        'domain' => $ipMap->getCompanyDomain()
    ]);

    if (!$account) {
        $account = new AbmAccount();
        $account->setAccountName($ipMap->getCompanyName());
        $account->setDomain($ipMap->getCompanyDomain());
        $account->setCountry($ipMap->getCountry());
        $account->setFirstSeenAt(new \DateTime());

        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    return $account;
}
```

### WebEvent Tracking JavaScript

Add to `templates/base.html.twig` before `</body>`:

```html
<script>
  (function () {
    // Track page view
    var data = {
      url: window.location.href,
      referrer: document.referrer,
      title: document.title,
      timestamp: new Date().toISOString(),
    };

    // Send beacon (doesn't block page unload)
    navigator.sendBeacon("/api/track", JSON.stringify(data));
  })();
</script>
```

---

## 7. ABM - Dashboard Display

**File**: `src/Controller/AbmDashboardController.php`  
**Method**: `index()`  
**Priority**: 🟢 **Medium**

### Implementation

```php
public function index(): Response
{
    // Get recent hits (last 24 hours)
    $since24h = new \DateTime('-24 hours');

    $recentHits = $this->entityManager->getRepository(AbmHit::class)
        ->createQueryBuilder('h')
        ->leftJoin('h.account', 'a')
        ->addSelect('a')
        ->where('h.hitAt >= :since')
        ->setParameter('since', $since24h)
        ->orderBy('h.hitAt', 'DESC')
        ->setMaxResults(50)
        ->getQuery()
        ->getResult();

    // Get top engaged accounts (last 7 days)
    $since7d = new \DateTime('-7 days');

    $topAccounts = $this->entityManager->getRepository(AbmAccount::class)
        ->createQueryBuilder('a')
        ->select('a', 'COUNT(h.id) as hitCount')
        ->leftJoin('a.abmHits', 'h')
        ->where('h.hitAt >= :since')
        ->setParameter('since', $since7d)
        ->groupBy('a.id')
        ->orderBy('hitCount', 'DESC')
        ->setMaxResults(10)
        ->getQuery()
        ->getResult();

    // Get metrics
    $metrics = [
        'visitors24h' => count($recentHits),
        'uniqueAccounts24h' => $this->entityManager->getRepository(AbmAccount::class)
            ->createQueryBuilder('a')
            ->select('COUNT(DISTINCT a.id)')
            ->leftJoin('a.abmHits', 'h')
            ->where('h.hitAt >= :since')
            ->setParameter('since', $since24h)
            ->getQuery()
            ->getSingleScalarResult(),
        'newAccounts24h' => $this->entityManager->getRepository(AbmAccount::class)
            ->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.firstSeenAt >= :since')
            ->setParameter('since', $since24h)
            ->getQuery()
            ->getSingleScalarResult(),
    ];

    return $this->render('abm_dashboard/index.html.twig', [
        'recentHits' => $recentHits,
        'topAccounts' => $topAccounts,
        'metrics' => $metrics,
    ]);
}
```

---

## Testing Strategy

### 1. Unit Tests

Create test files in `tests/Unit/Service/`:

```php
// tests/Unit/Service/DatasetImportServiceTest.php
class DatasetImportServiceTest extends TestCase
{
    public function testImportTariffData(): void
    {
        $service = new DatasetImportService(...);

        $result = $service->importTariffData(
            'tests/fixtures/tariff_rates_sample.csv',
            'tests/fixtures/tariff_rates_sample.csv.sha256',
            'Test Import'
        );

        $this->assertArrayHasKey('versionId', $result);
        $this->assertGreaterThan(0, $result['recordsImported']);
    }
}
```

### 2. Integration Tests

```php
// tests/Integration/QuoteCoPilotTest.php
class QuoteCoPilotTest extends WebTestCase
{
    public function testBomUploadAndProcessing(): void
    {
        $client = static::createClient();

        // Upload BOM file
        $client->request('POST', '/quote-copilot/process', [
            'companyId' => 1,
            'quantity' => 100,
        ], [
            'bomFile' => new UploadedFile(
                'tests/fixtures/sample_bom.csv',
                'sample_bom.csv'
            )
        ]);

        $this->assertResponseIsSuccessful();
    }
}
```

### 3. Manual Testing Checklist

- [ ] Upload sample BOM CSV with 10 lines
- [ ] Verify all lines are parsed correctly
- [ ] Check coverage percentage calculation
- [ ] Generate PDF and verify content
- [ ] Import tariff rates CSV (100 rows)
- [ ] Import freight tables CSV (50 rows)
- [ ] Calculate landed cost for US destination
- [ ] Calculate landed cost for EU destination
- [ ] Verify FTA savings shown for Morocco-US
- [ ] Test ABM IP resolution with known IPs
- [ ] View ABM dashboard and check metrics

---

## Next Steps

1. **Start with Phase 1** - Implement dataset imports first (foundation)
2. **Test each feature** - Unit tests + manual testing
3. **Move to Phase 2** - Quote features depend on datasets
4. **Iterate** - Get feedback, refine, optimize
5. **Document** - Update API docs, user guides

---

**Questions or Issues?**

- Check existing implementations in the codebase
- Review TODO comments in service files
- Refer to entity definitions for data structures
- Test with small datasets first, then scale up

**Good luck! 🚀**
