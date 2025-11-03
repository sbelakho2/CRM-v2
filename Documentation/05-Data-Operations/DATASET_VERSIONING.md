# Dataset Versioning & Import Strategy

**Version:** 1.0  
**Last Updated:** October 29, 2025  
**Service:** `DatasetImportService`

---

## Overview

The Dataset Versioning system manages import, versioning, and rollback of critical reference datasets used in landed-cost calculations:

1. **Tariff Rates** (HTS codes, duty rates, MFN vs FTA)
2. **Freight Tables** (shipping costs by mode, weight, origin-destination)
3. **FX Rates** (currency exchange rates, historical snapshots)
4. **HTS Map Rules** (product description → HTS code mapping)
5. **FTA Rules** (free trade agreement eligibility criteria)
6. **Route Preferences** (optimal shipping routes by product type)

### Key Features
- **UUID Versioning:** Each import has a unique identifier
- **Snapshot on Import:** Complete dataset state preserved
- **Rollback Support:** Revert to any previous version
- **SHA-256 Verification:** Integrity checking for DMZ imports
- **Audit Trail:** Full history of who imported what and when

---

## Architecture

### DMZ (Demilitarized Zone)
- **Location:** External server, accessible to customs/freight data providers
- **Purpose:** Receive daily data dumps from government agencies, freight forwarders
- **Security:** Read-only access, no direct connection to production database
- **Format:** CSV files with SHA-256 signature files

### LAN (Local Area Network)
- **Location:** Production Symfony application server
- **Purpose:** Import verified datasets into SQLite database
- **Security:** Signature verification before import, audit logging
- **Format:** SQLite tables (TariffRate, FreightTable, FxRate, etc.)

### Data Flow
```
External Providers
    ↓
    (SFTP/API)
    ↓
DMZ Server
    ↓
    CSV Files + SHA-256 Signatures
    ↓
    (Manual Transfer or Cron Job)
    ↓
LAN Import Service
    ↓
    Verify Signature → Parse CSV → Database Insert
    ↓
SQLite Database
```

---

## Dataset Types

### 1. Tariff Rates

**Entity:** `TariffRate`

**Fields:**
- `htsCode` (e.g., "8542.31.00")
- `description` (e.g., "Processors and controllers")
- `dutyType` (AD_VALOREM, SPECIFIC, MIXED)
- `dutyRateMfn` (e.g., 0.0, 5.3)
- `dutyRateFta` (e.g., 0.0 for Morocco-US FTA)
- `dutyUnit` (%, kg, m²)
- `originCountry` (e.g., "MA", "CN")
- `destCountry` (e.g., "US", "FR")
- `asofDate` (e.g., "2025-01-01")
- `versionUuid` (e.g., "550e8400-e29b-41d4-a716-446655440000")

**Import Frequency:** Weekly (US HTS changes quarterly, but freight costs weekly)

**CSV Format:**
```csv
htsCode,description,dutyType,dutyRateMfn,dutyRateFta,dutyUnit,originCountry,destCountry,asofDate,versionUuid
8542.31.00,Processors and controllers,AD_VALOREM,0.0,0.0,%,MA,US,2025-01-01,550e8400-...
8542.32.00,Memories,AD_VALOREM,0.0,0.0,%,MA,US,2025-01-01,550e8400-...
```

**Signature File:** `tariff_rates_2025-01-01.csv.sha256`
```
a3f5b8c9d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8  tariff_rates_2025-01-01.csv
```

### 2. Freight Tables

**Entity:** `FreightTable`

**Fields:**
- `mode` (AIR, LCL, FCL)
- `originPort` (e.g., "CAS" for Casablanca)
- `destPort` (e.g., "LAX" for Los Angeles)
- `minWeightKg` (e.g., 0)
- `maxWeightKg` (e.g., 100)
- `pricePerKg` (e.g., 4.50)
- `transitDays` (e.g., 5)
- `carrier` (e.g., "DHL", "Maersk")
- `asofDate` (e.g., "2025-01-15")
- `versionUuid`

**Import Frequency:** Weekly (freight rates fluctuate with fuel prices, demand)

**CSV Format:**
```csv
mode,originPort,destPort,minWeightKg,maxWeightKg,pricePerKg,transitDays,carrier,asofDate,versionUuid
AIR,CAS,LAX,0,100,4.50,5,DHL,2025-01-15,660e9500-...
AIR,CAS,LAX,100,500,3.80,5,DHL,2025-01-15,660e9500-...
LCL,CAS,LAX,0,5000,1.20,25,Maersk,2025-01-15,660e9500-...
```

### 3. FX Rates

**Entity:** `FxRate`

**Fields:**
- `fromCurrency` (e.g., "MAD")
- `toCurrency` (e.g., "USD")
- `rate` (e.g., 0.10)
- `asofDate` (e.g., "2025-01-20")
- `source` (e.g., "ECB", "Bank Al-Maghrib")
- `versionUuid`

**Import Frequency:** Daily (currency markets change daily)

**CSV Format:**
```csv
fromCurrency,toCurrency,rate,asofDate,source,versionUuid
MAD,USD,0.10,2025-01-20,Bank Al-Maghrib,770e0600-...
USD,MAD,10.00,2025-01-20,Bank Al-Maghrib,770e0600-...
EUR,USD,1.08,2025-01-20,ECB,770e0600-...
```

---

## UUID Versioning

### Purpose
- Uniquely identify each import batch
- Enable multiple versions to coexist (for comparison)
- Support rollback to any previous version

### Generation
```php
use Ramsey\Uuid\Uuid;

$versionUuid = Uuid::uuid4()->toString();
// Example: "550e8400-e29b-41d4-a716-446655440000"
```

### Storage
- All dataset entities have a `versionUuid` column
- ReportAudit table tracks version metadata:
  - `versionUuid`
  - `importedAt` (timestamp)
  - `importedBy` (user ID)
  - `recordCount` (number of rows imported)
  - `sourceFile` (CSV filename)
  - `sha256Hash` (file integrity hash)

### Active Version
- The system uses the **latest version by `asofDate`** for calculations
- Operators can manually select a version if needed (e.g., for historical quotes)

---

## Import Process

### Step 1: File Transfer from DMZ

**Manual Transfer (Initial Implementation):**
```powershell
# On DMZ server
scp tariff_rates_2025-01-01.csv admin@lan-server:/var/imports/
scp tariff_rates_2025-01-01.csv.sha256 admin@lan-server:/var/imports/
```

**Automated Transfer (Future):**
```php
// Cron job runs daily
// PHP script connects to DMZ via SFTP
$connection = ssh2_connect('dmz-server.example.com', 22);
ssh2_auth_pubkey_file($connection, 'import-user', '/path/to/public_key', '/path/to/private_key');
ssh2_scp_recv($connection, '/exports/tariff_rates_latest.csv', '/var/imports/tariff_rates.csv');
```

### Step 2: Signature Verification

**Algorithm:** SHA-256

**Purpose:** Ensure file integrity, detect tampering

**Implementation:**
```php
public function verifySignature(string $filePath, string $signatureFile): bool
{
    $expectedHash = trim(file_get_contents($signatureFile));
    $actualHash = hash_file('sha256', $filePath);
    
    if ($expectedHash !== $actualHash) {
        $this->logger->error('Signature verification failed', [
            'file' => $filePath,
            'expected' => $expectedHash,
            'actual' => $actualHash
        ]);
        return false;
    }
    
    return true;
}
```

**Error Handling:**
```php
if (!$this->verifySignature($csvPath, $signaturePath)) {
    throw new \RuntimeException('Dataset import aborted: signature verification failed');
}
```

### Step 3: CSV Parsing

**Library:** Symfony Serializer or League CSV

**Example:**
```php
use League\Csv\Reader;

$csv = Reader::createFromPath($filePath, 'r');
$csv->setHeaderOffset(0); // First row is header

$records = $csv->getRecords();
foreach ($records as $record) {
    $tariffRate = new TariffRate();
    $tariffRate->setHtsCode($record['htsCode']);
    $tariffRate->setDescription($record['description']);
    $tariffRate->setDutyType($record['dutyType']);
    $tariffRate->setDutyRateMfn((float) $record['dutyRateMfn']);
    $tariffRate->setDutyRateFta((float) $record['dutyRateFta']);
    $tariffRate->setDutyUnit($record['dutyUnit']);
    $tariffRate->setOriginCountry($record['originCountry']);
    $tariffRate->setDestCountry($record['destCountry']);
    $tariffRate->setAsofDate(new \DateTime($record['asofDate']));
    $tariffRate->setVersionUuid($record['versionUuid']);
    
    $this->entityManager->persist($tariffRate);
}

$this->entityManager->flush();
```

### Step 4: Snapshot Creation

**Purpose:** Preserve complete dataset state for rollback

**Approach:** All rows share the same `versionUuid`, enabling filtered queries

**Query to Restore Version:**
```sql
-- Get all tariff rates for version 550e8400-...
SELECT * FROM tariff_rate WHERE version_uuid = '550e8400-e29b-41d4-a716-446655440000';
```

**Alternative (Separate Snapshot Table):**
```php
// For very large datasets, create a snapshot table
CREATE TABLE tariff_rate_snapshot AS SELECT * FROM tariff_rate WHERE version_uuid = '...';
```

### Step 5: Audit Logging

**Entity:** `ReportAudit`

**Log Entry:**
```php
$audit = new ReportAudit();
$audit->setReportType('DATASET_IMPORT_TARIFF_RATES');
$audit->setVersionUuid($versionUuid);
$audit->setRunBy($this->security->getUser());
$audit->setRunAt(new \DateTime());
$audit->setOutputJson(json_encode([
    'sourceFile' => 'tariff_rates_2025-01-01.csv',
    'recordCount' => 15420,
    'sha256Hash' => $actualHash,
    'importDurationSeconds' => 12.5
]));

$this->entityManager->persist($audit);
$this->entityManager->flush();
```

---

## Rollback Process

### Scenario
An erroneous dataset was imported on 2025-01-22. Operator needs to revert to the 2025-01-15 version.

### Steps

**1. Identify Previous Version**
```php
$previousAudit = $this->reportAuditRepo->findOneBy(
    ['reportType' => 'DATASET_IMPORT_TARIFF_RATES'],
    ['runAt' => 'DESC'],
    offset: 1 // Skip latest, get second-latest
);

$rollbackUuid = $previousAudit->getVersionUuid();
```

**2. Mark Current Version as Inactive**
```sql
UPDATE tariff_rate
SET active = 0
WHERE version_uuid = 'current-version-uuid';
```

**3. Activate Previous Version**
```sql
UPDATE tariff_rate
SET active = 1
WHERE version_uuid = 'rollback-version-uuid';
```

**Alternative (Delete Current, Keep Snapshot):**
```sql
-- Delete current version
DELETE FROM tariff_rate WHERE version_uuid = 'current-version-uuid';

-- Previous version rows still exist, automatically become "active"
```

**4. Log Rollback Action**
```php
$audit = new ReportAudit();
$audit->setReportType('DATASET_ROLLBACK_TARIFF_RATES');
$audit->setVersionUuid($rollbackUuid);
$audit->setRunBy($this->security->getUser());
$audit->setRunAt(new \DateTime());
$audit->setOutputJson(json_encode([
    'fromVersion' => 'current-version-uuid',
    'toVersion' => $rollbackUuid,
    'reason' => 'Erroneous duty rates detected'
]));

$this->entityManager->persist($audit);
$this->entityManager->flush();
```

---

## Comparison Between Versions

### Use Case
Operator wants to see what changed between the 2025-01-15 and 2025-01-22 imports.

### Query
```sql
-- Find HTS codes that exist in both versions but have different duty rates
SELECT
    v1.hts_code,
    v1.duty_rate_mfn AS old_rate,
    v2.duty_rate_mfn AS new_rate,
    (v2.duty_rate_mfn - v1.duty_rate_mfn) AS change
FROM tariff_rate v1
JOIN tariff_rate v2 ON v1.hts_code = v2.hts_code
    AND v1.origin_country = v2.origin_country
    AND v1.dest_country = v2.dest_country
WHERE v1.version_uuid = '660e9500-...'  -- 2025-01-15 version
  AND v2.version_uuid = '770e0600-...'  -- 2025-01-22 version
  AND v1.duty_rate_mfn != v2.duty_rate_mfn
ORDER BY ABS(v2.duty_rate_mfn - v1.duty_rate_mfn) DESC;
```

**PHP Service Method:**
```php
public function compareVersions(string $oldUuid, string $newUuid): array
{
    $sql = "
        SELECT
            v1.hts_code,
            v1.duty_rate_mfn AS old_rate,
            v2.duty_rate_mfn AS new_rate,
            (v2.duty_rate_mfn - v1.duty_rate_mfn) AS change
        FROM tariff_rate v1
        JOIN tariff_rate v2 ON v1.hts_code = v2.hts_code
        WHERE v1.version_uuid = :oldUuid
          AND v2.version_uuid = :newUuid
          AND v1.duty_rate_mfn != v2.duty_rate_mfn
        ORDER BY ABS(change) DESC
    ";
    
    $stmt = $this->entityManager->getConnection()->prepare($sql);
    $result = $stmt->executeQuery(['oldUuid' => $oldUuid, 'newUuid' => $newUuid]);
    
    return $result->fetchAllAssociative();
}
```

---

## Performance Optimization

### Problem
Importing 15,000+ tariff rates in a single transaction can cause memory issues and lock the database.

### Solution: Batch Processing

**Implementation:**
```php
public function importTariffRates(string $csvPath, string $versionUuid): int
{
    $csv = Reader::createFromPath($csvPath, 'r');
    $csv->setHeaderOffset(0);
    
    $records = $csv->getRecords();
    $batchSize = 500;
    $count = 0;
    
    foreach ($records as $record) {
        $tariffRate = new TariffRate();
        // ... set properties ...
        
        $this->entityManager->persist($tariffRate);
        
        if (++$count % $batchSize === 0) {
            $this->entityManager->flush();
            $this->entityManager->clear(); // Free memory
        }
    }
    
    // Flush remaining
    $this->entityManager->flush();
    $this->entityManager->clear();
    
    return $count;
}
```

**Benefits:**
- **Memory Usage:** Constant (only 500 entities in memory at a time)
- **Transaction Safety:** Each batch committed separately
- **Progress Tracking:** Can log after each batch

---

## Data Validation

### Pre-Import Validation

**Check 1: Required Fields**
```php
if (empty($record['htsCode']) || empty($record['dutyRateMfn'])) {
    throw new \InvalidArgumentException("Missing required fields in row {$rowNumber}");
}
```

**Check 2: Data Types**
```php
if (!is_numeric($record['dutyRateMfn'])) {
    throw new \InvalidArgumentException("dutyRateMfn must be numeric in row {$rowNumber}");
}
```

**Check 3: Valid Enums**
```php
$validDutyTypes = ['AD_VALOREM', 'SPECIFIC', 'MIXED'];
if (!in_array($record['dutyType'], $validDutyTypes)) {
    throw new \InvalidArgumentException("Invalid dutyType in row {$rowNumber}");
}
```

**Check 4: Date Format**
```php
try {
    $asofDate = new \DateTime($record['asofDate']);
} catch (\Exception $e) {
    throw new \InvalidArgumentException("Invalid date format in row {$rowNumber}");
}
```

### Post-Import Validation

**Check 1: Record Count**
```php
$importedCount = $this->tariffRateRepo->count(['versionUuid' => $versionUuid]);
$csvRowCount = iterator_count($csv->getRecords());

if ($importedCount !== $csvRowCount) {
    $this->logger->error('Record count mismatch', [
        'expected' => $csvRowCount,
        'actual' => $importedCount
    ]);
}
```

**Check 2: Duplicate Detection**
```php
$sql = "
    SELECT hts_code, origin_country, dest_country, COUNT(*) as cnt
    FROM tariff_rate
    WHERE version_uuid = :versionUuid
    GROUP BY hts_code, origin_country, dest_country
    HAVING cnt > 1
";

$duplicates = $this->entityManager->getConnection()->executeQuery($sql, ['versionUuid' => $versionUuid])->fetchAllAssociative();

if (!empty($duplicates)) {
    $this->logger->warning('Duplicates detected', ['duplicates' => $duplicates]);
}
```

---

## Admin UI

### Dataset List Page

**Route:** `/admin/datasets`

**Features:**
- List all dataset types (Tariff Rates, Freight Tables, FX Rates)
- Show latest version for each type
- Display last import date, record count
- Provide "Import New Version" button

**Example Table:**
```
Dataset Type       | Latest Version | As Of Date | Record Count | Last Import
-------------------+----------------+------------+--------------+------------------
Tariff Rates       | 770e0600-...   | 2025-01-22 | 15,420       | 2025-01-22 10:15
Freight Tables     | 660e9500-...   | 2025-01-15 | 2,350        | 2025-01-15 08:30
FX Rates           | 880e0700-...   | 2025-01-23 | 45           | 2025-01-23 06:00
HTS Map Rules      | 550e8400-...   | 2025-01-01 | 8,720        | 2025-01-10 14:20
FTA Rules          | 440e7300-...   | 2024-12-15 | 1,250        | 2024-12-20 11:45
Route Preferences  | 330e6200-...   | 2024-11-01 | 340          | 2024-11-05 09:10
```

### Import Form

**Route:** `/admin/datasets/import`

**Fields:**
- Dataset Type (dropdown: Tariff Rates, Freight Tables, etc.)
- CSV File (file upload)
- SHA-256 Signature File (file upload)
- As Of Date (date picker)

**Validation:**
- File size limit: 50MB
- Allowed extensions: .csv only
- Signature file required

**Process:**
1. Upload CSV and signature
2. Verify signature
3. Parse CSV (show preview of first 10 rows)
4. Operator confirms import
5. Background job processes full import (Symfony Messenger)
6. Progress bar shows status
7. Success/error message displayed

### Version History

**Route:** `/admin/datasets/{type}/versions`

**Features:**
- List all versions for a dataset type
- Show version UUID, import date, record count, imported by
- Provide "Rollback to This Version" button
- Provide "Compare with Current" button

**Example Table:**
```
Version UUID       | As Of Date | Record Count | Imported By | Imported At      | Actions
-------------------+------------+--------------+-------------+------------------+----------
770e0600-...       | 2025-01-22 | 15,420       | admin       | 2025-01-22 10:15 | [Current]
660e9500-...       | 2025-01-15 | 15,380       | admin       | 2025-01-15 08:30 | [Rollback] [Compare]
550e8400-...       | 2025-01-08 | 15,340       | admin       | 2025-01-08 07:45 | [Rollback] [Compare]
```

### Rollback Confirmation

**Modal:**
```
Are you sure you want to rollback to version 660e9500-...?

This will:
- Deactivate the current version (770e0600-...)
- Activate the selected version (660e9500-...)
- All quotes calculated after 2025-01-15 may show different duty rates

[Cancel] [Confirm Rollback]
```

---

## Security Considerations

### 1. Access Control
- Only ROLE_ADMIN can import datasets
- Only ROLE_ADMIN can rollback versions
- Audit log records who performed each action

### 2. Signature Verification
- SHA-256 ensures file integrity
- Reject imports if signature verification fails
- Log all verification failures

### 3. SQL Injection Prevention
- Use Doctrine ORM for all queries
- Parameterized queries for raw SQL
- Never concatenate user input into SQL

### 4. File Upload Security
- Validate file extensions (.csv only)
- Scan for malware (if possible)
- Store uploaded files outside web root
- Delete temporary files after import

---

## Testing

### Unit Tests

**Test: Signature Verification**
```php
public function testVerifySignature(): void
{
    $service = new DatasetImportService(...);
    
    // Valid signature
    $this->assertTrue($service->verifySignature(
        __DIR__ . '/fixtures/tariff_rates.csv',
        __DIR__ . '/fixtures/tariff_rates.csv.sha256'
    ));
    
    // Invalid signature
    $this->assertFalse($service->verifySignature(
        __DIR__ . '/fixtures/tampered_file.csv',
        __DIR__ . '/fixtures/tariff_rates.csv.sha256'
    ));
}
```

**Test: CSV Parsing**
```php
public function testImportTariffRates(): void
{
    $service = new DatasetImportService(...);
    $versionUuid = Uuid::uuid4()->toString();
    
    $count = $service->importTariffRates(
        __DIR__ . '/fixtures/tariff_rates_sample.csv',
        $versionUuid
    );
    
    $this->assertEquals(100, $count);
    
    // Verify database
    $imported = $this->tariffRateRepo->findBy(['versionUuid' => $versionUuid]);
    $this->assertCount(100, $imported);
}
```

### Integration Tests

**Test: Full Import Flow**
```php
public function testFullImportFlow(): void
{
    // 1. Upload files
    $csvFile = new UploadedFile(__DIR__ . '/fixtures/tariff_rates.csv', 'tariff_rates.csv');
    $sigFile = new UploadedFile(__DIR__ . '/fixtures/tariff_rates.csv.sha256', 'tariff_rates.csv.sha256');
    
    // 2. Submit import form
    $client = static::createClient();
    $client->request('POST', '/admin/datasets/import', [], [
        'csv_file' => $csvFile,
        'signature_file' => $sigFile,
        'dataset_type' => 'tariff_rates',
        'asof_date' => '2025-01-22'
    ]);
    
    // 3. Verify response
    $this->assertResponseIsSuccessful();
    
    // 4. Verify database
    $imported = $this->tariffRateRepo->findAll();
    $this->assertNotEmpty($imported);
}
```

---

## Roadmap

### Phase 1 (Current)
- ✅ Manual file transfer from DMZ
- ✅ Signature verification
- ✅ CSV parsing and import
- ✅ Version tracking (UUID)
- ✅ Audit logging

### Phase 2 (Q1 2026)
- ⏳ Automated SFTP transfer from DMZ
- ⏳ Scheduled imports (cron job)
- ⏳ Email notifications on import success/failure
- ⏳ Version comparison UI

### Phase 3 (Q2 2026)
- ⏳ Real-time data feeds (API instead of CSV)
- ⏳ Incremental updates (only changed rows)
- ⏳ Multi-tenant support (different datasets per customer)

---

**Document End**
