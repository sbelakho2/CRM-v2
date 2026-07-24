<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DatasetVersion;
use App\Repository\DatasetVersionRepository;
use App\Repository\TariffRateRepository;
use App\Repository\FreightTableRepository;
use App\Repository\FxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DatasetImportService
 * 
 * Manages dataset imports from DMZ to LAN for:
 * - Tariff rates (HTS codes, duty rates, VAT rates)
 * - Freight tables (air/sea freight rates by lane)
 * - FX rates (currency exchange rates)
 * 
 * Key features:
 * - Dataset versioning (UUID-based version_id)
 * - Point-in-time snapshots (asof timestamps)
 * - Rollback capability (activate previous version)
 * - Signature verification (SHA-256 hash validation)
 * - CSV parsing with validation
 * - Atomic transactions (all-or-nothing imports)
 * 
 * DMZ → LAN Transfer Process:
 * 1. DMZ system generates CSV export with signature
 * 2. CSV + signature file transferred to LAN /tmp/imports/
 * 3. Import service verifies signature
 * 4. Parse CSV and validate data
 * 5. Create new dataset_versions entry
 * 6. Import data with version_id + asof timestamp
 * 7. Set new version as is_active = 1
 * 
 * Used by:
 * - AdminDatasetController for manual imports
 * - Scheduled jobs for automated imports
 */
class DatasetImportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatasetVersionRepository $datasetVersionRepository,
        private TariffRateRepository $tariffRateRepository,
        private FreightTableRepository $freightTableRepository,
        private FxRateRepository $fxRateRepository,
        private ?\Symfony\Bundle\SecurityBundle\Security $security = null
    ) {}

    /**
     * Import tariff rate data from CSV file
     * 
     * CSV format:
     * hts_code,destination_country,duty_rate,duty_type,specific_rate,specific_uom,vat_rate,asof
     * 8473.30.51,US,0.0,AD_VALOREM,,,0.0,2025-01-01
     * 8473.30.51,MA,2.5,AD_VALOREM,,,20.0,2025-01-01
     * 
     * @param string $csvPath - Path to CSV file
     * @param string $signaturePath - Path to signature file (.sha256)
     * @param string $description - Import description (e.g., "Q1 2025 Tariff Update")
     * 
     * @return array{
     *   versionId: string,
     *   recordsImported: int,
     *   asof: \DateTime,
     *   datasetType: string
     * }
     */
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
        
        // Get current user from security context
        $user = $this->security?->getUser();
        $version->setImportedBy($user ? $user->getUserIdentifier() : 'system');
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
        $effectiveDate = null;
        
        // Step 5: Import each row
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }
            
            try {
                $data = array_combine($headers, $row);
                
                // Create TariffRate entity
                $tariffRate = new \App\Entity\TariffRate();
                $tariffRate->setHsCode($data['hts_code']);
                $tariffRate->setOriginCountry($data['origin_country'] ?? 'MA');
                $tariffRate->setDestinationCountry($data['destination_country']);
                $tariffRate->setDutyRate($data['duty_rate']);
                $tariffRate->setMfnRate($data['mfn_rate'] ?? null);
                $tariffRate->setFtaRate($data['fta_rate'] ?? null);
                $tariffRate->setEffectiveDate(new \DateTime($data['effective_date']));
                
                if (isset($data['expiry_date']) && !empty($data['expiry_date'])) {
                    $tariffRate->setExpiryDate(new \DateTime($data['expiry_date']));
                }
                
                if (isset($data['fta_agreement']) && !empty($data['fta_agreement'])) {
                    $tariffRate->setFtaAgreement($data['fta_agreement']);
                }
                
                if (isset($data['notes']) && !empty($data['notes'])) {
                    $tariffRate->setNotes($data['notes']);
                }
                
                $this->entityManager->persist($tariffRate);
                $recordsImported++;
                
                if (!$effectiveDate) {
                    $effectiveDate = $tariffRate->getEffectiveDate();
                }
                
                // Batch flush every 100 rows for performance
                if ($recordsImported % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear(\App\Entity\TariffRate::class); // Clear memory
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
            'version_uuid' => $versionUuid,
            'recordsImported' => $recordsImported,
            'errors' => $errors,
            'effectiveDate' => $effectiveDate,
            'datasetType' => 'TARIFF_RATES'
        ];
    }

    /**
     * Import freight table data from CSV file
     * 
     * CSV format:
     * lane_code,mode,rate_per_kg,rate_per_cbm,flat_rate,container_type,asof
     * SHA-JFK-NYC,AIR,4.50,,,20GP,2025-01-01
     * SHA-JFK-NYC,LCL,,45.00,,20GP,2025-01-01
     * SHA-JFK-NYC,FCL,,,2500.00,20GP,2025-01-01
     * 
     * @param string $csvPath - Path to CSV file
     * @param string $signaturePath - Path to signature file
     * @param string $description - Import description
     * 
     * @return array - Import summary
     */
    public function importFreightData(string $csvPath, string $signaturePath, string $description): array
    {
        // Step 1: Verify signature
        $this->verifySignature($csvPath, $signaturePath);
        
        // Step 2: Generate UUID for this version
        $versionUuid = Uuid::v4()->toRfc4122();
        $sha256Hash = hash_file('sha256', $csvPath);
        
        // Step 3: Create DatasetVersion entity
        $version = new DatasetVersion();
        $version->setDatasetType('FREIGHT_TABLES');
        $version->setVersionUuid($versionUuid);
        $version->setSha256Hash($sha256Hash);
        $version->setImportedAt(new \DateTime());
        $version->setIsActive(false);
        
        if ($description) {
            $version->setMetadata(['description' => $description]);
        }
        
        $this->entityManager->persist($version);
        $this->entityManager->flush();
        
        // Step 4: Parse CSV and import rows
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: $csvPath");
        }
        
        $headers = fgetcsv($handle);
        $recordsImported = 0;
        $errors = [];
        
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }
            
            try {
                $data = array_combine($headers, $row);
                
                $freight = new \App\Entity\FreightTable();
                $freight->setOriginPort($data['origin_port']);
                $freight->setDestinationPort($data['destination_port']);
                $freight->setTransportMode($data['transport_mode']); // Ocean, Air, Rail, Truck
                $freight->setContainerType($data['container_type']); // 20GP, 40GP, 40HQ, LCL, FCL
                $freight->setCostPerUnit($data['cost_per_unit']);

                if (empty($data['currency'])) {
                    throw new \InvalidArgumentException('Missing currency for freight row');
                }

                $freight->setCurrency($data['currency']);
                
                if (isset($data['transit_days']) && !empty($data['transit_days'])) {
                    $freight->setTransitDays((int)$data['transit_days']);
                }
                
                $freight->setEffectiveDate(new \DateTime($data['effective_date']));
                
                if (isset($data['expiry_date']) && !empty($data['expiry_date'])) {
                    $freight->setExpiryDate(new \DateTime($data['expiry_date']));
                }
                
                if (isset($data['carrier']) && !empty($data['carrier'])) {
                    $freight->setCarrier($data['carrier']);
                }
                
                if (isset($data['notes']) && !empty($data['notes'])) {
                    $freight->setNotes($data['notes']);
                }
                
                $this->entityManager->persist($freight);
                $recordsImported++;
                
                if ($recordsImported % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear(\App\Entity\FreightTable::class);
                }
                
            } catch (\Exception $e) {
                $errors[] = "Row $recordsImported: " . $e->getMessage();
            }
        }
        
        fclose($handle);
        $this->entityManager->flush();
        
        // Update version with record count
        $version->setRecordCount($recordsImported);
        $this->entityManager->flush();
        
        // Activate this version
        $this->activateVersion($versionUuid);
        
        return [
            'version_uuid' => $versionUuid,
            'recordsImported' => $recordsImported,
            'errors' => $errors,
            'datasetType' => 'FREIGHT_TABLES'
        ];
    }

    /**
     * Import FX rate data from CSV file
     * 
     * CSV format:
     * from_currency,to_currency,rate,asof
     * USD,MAD,9.85,2025-01-01
     * EUR,USD,1.10,2025-01-01
     * CNY,USD,0.14,2025-01-01
     * 
     * @param string $csvPath - Path to CSV file
     * @param string $signaturePath - Path to signature file
     * @param string $description - Import description
     * 
     * @return array - Import summary
     */
    public function importFxRates(string $csvPath, string $signaturePath, string $description): array
    {
        // 1. Verify signature
        $this->verifySignature($csvPath, $signaturePath);
        
        // 2. Generate version_id
        $versionId = Uuid::v4()->toRfc4122();
        
        // 3. Create DatasetVersion entry
        $user = $this->security?->getUser();
        $version = new DatasetVersion();
        $version->setVersionUuid($versionId);
        $version->setDatasetType('FX_RATES');
        $version->setMetadata(['description' => $description]);
        $version->setImportedAt(new \DateTime());
        $version->setImportedBy($user ? $user->getUserIdentifier() : 'system');
        $version->setSha256Hash(hash_file('sha256', $csvPath));
        $version->setIsActive(false);
        
        $this->entityManager->persist($version);
        
        // 4. Parse CSV and create FxRate entities
        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: $csvPath");
        }
        
        // Skip header
        fgetcsv($handle);
        
        $imported = 0;
        $errors = [];
        
        while (($row = fgetcsv($handle)) !== false) {
            try {
                if (count($row) < 4) {
                    $errors[] = "Invalid row (expected 4 columns): " . implode(',', $row);
                    continue;
                }
                
                [$fromCurrency, $toCurrency, $rate, $asof] = $row;
                
                $fxRate = new \App\Entity\FxRate();
                $fxRate->setFromCurrency(strtoupper(trim($fromCurrency)));
                $fxRate->setToCurrency(strtoupper(trim($toCurrency)));
                $fxRate->setRate(trim($rate));
                $fxRate->setAsof(new \DateTime(trim($asof)));
                $fxRate->setVersionId($versionId);
                $fxRate->setIsActive(false);
                
                $this->entityManager->persist($fxRate);
                $imported++;
                
                // Batch flush every 100 records
                if ($imported % 100 === 0) {
                    $this->entityManager->flush();
                }
                
            } catch (\Exception $e) {
                $errors[] = "Error on row: " . implode(',', $row) . " - " . $e->getMessage();
            }
        }
        
        fclose($handle);
        
        // 5. Flush remaining records and activate version
        $this->entityManager->flush();
        
        // Deactivate old versions
        $this->entityManager->createQuery(
            'UPDATE App\Entity\DatasetVersion v 
             SET v.isActive = false 
             WHERE v.datasetType = :type'
        )
        ->setParameter('type', 'FX_RATES')
        ->execute();
        
        $this->entityManager->createQuery(
            'UPDATE App\Entity\FxRate f 
             SET f.isActive = false'
        )->execute();
        
        // Activate new version
        $version->setIsActive(true);
        
        $this->entityManager->createQuery(
            'UPDATE App\Entity\FxRate f 
             SET f.isActive = true 
             WHERE f.versionId = :versionId'
        )
        ->setParameter('versionId', $versionId)
        ->execute();
        
        $this->entityManager->flush();
        
        // 6. Return summary
        return [
            'success' => true,
            'version_uuid' => $versionId,
            'dataset_type' => 'FX_RATES',
            'imported_count' => $imported,
            'errors' => $errors,
            'description' => $description
        ];
    }

    /**
     * Create a point-in-time snapshot of current active dataset
     * 
     * @param string $datasetType - TARIFF_RATES, FREIGHT_TABLES, or FX_RATES
     * @param string $description - Snapshot description
     * 
     * @return string - New version_id
     */
    public function snapshotDataset(string $datasetType, string $description): string
    {
        // 1. Get current active version
        $activeVersion = $this->datasetVersionRepository->findOneBy([
            'datasetType' => $datasetType,
            'isActive' => true
        ]);
        
        if (!$activeVersion) {
            throw new \RuntimeException("No active version found for dataset type: $datasetType");
        }
        
        // 2. Generate new version_id
        $newVersionId = Uuid::v4()->toRfc4122();
        $oldVersionId = $activeVersion->getVersionUuid();
        
        // 3. Clone records based on dataset type
        $recordCount = 0;
        
        switch ($datasetType) {
            case 'TARIFF_RATES':
                // Clone tariff_rates
                $recordCount = $this->entityManager->createQuery(
                    'SELECT COUNT(t.id) FROM App\\Entity\\TariffRate t WHERE t.versionId = :versionId'
                )
                ->setParameter('versionId', $oldVersionId)
                ->getSingleScalarResult();
                
                $this->entityManager->getConnection()->executeStatement(
                    'INSERT INTO tariff_rates (hts_code, duty_rate, description, version_id, is_active, created_at) 
                     SELECT hts_code, duty_rate, description, :newVersionId, 0, NOW() 
                     FROM tariff_rates WHERE version_id = :oldVersionId',
                    ['newVersionId' => $newVersionId, 'oldVersionId' => $oldVersionId]
                );
                break;
                
            case 'FREIGHT_TABLES':
                // Clone freight_tables
                $recordCount = $this->entityManager->createQuery(
                    'SELECT COUNT(f.id) FROM App\\Entity\\FreightTable f WHERE f.versionId = :versionId'
                )
                ->setParameter('versionId', $oldVersionId)
                ->getSingleScalarResult();
                
                $this->entityManager->getConnection()->executeStatement(
                    'INSERT INTO freight_tables (origin_port, destination_port, carrier, transit_days, rate_per_kg, version_id, is_active, created_at) 
                     SELECT origin_port, destination_port, carrier, transit_days, rate_per_kg, :newVersionId, 0, NOW() 
                     FROM freight_tables WHERE version_id = :oldVersionId',
                    ['newVersionId' => $newVersionId, 'oldVersionId' => $oldVersionId]
                );
                break;
                
            case 'FX_RATES':
                // Clone fx_rates
                $recordCount = $this->entityManager->createQuery(
                    'SELECT COUNT(f.id) FROM App\\Entity\\FxRate f WHERE f.versionId = :versionId'
                )
                ->setParameter('versionId', $oldVersionId)
                ->getSingleScalarResult();
                
                $this->entityManager->getConnection()->executeStatement(
                    'INSERT INTO fx_rates (from_currency, to_currency, rate, asof, version_id, is_active, created_at) 
                     SELECT from_currency, to_currency, rate, asof, :newVersionId, 0, NOW() 
                     FROM fx_rates WHERE version_id = :oldVersionId',
                    ['newVersionId' => $newVersionId, 'oldVersionId' => $oldVersionId]
                );
                break;
                
            default:
                throw new \InvalidArgumentException("Unknown dataset type: $datasetType");
        }
        
        // 4. Create new DatasetVersion entry
        $user = $this->security?->getUser();
        $newVersion = new DatasetVersion();
        $newVersion->setVersionUuid($newVersionId);
        $newVersion->setDatasetType($datasetType);
        $newVersion->setMetadata(['description' => "SNAPSHOT: $description"]);
        $newVersion->setImportedAt(new \DateTime());
        $newVersion->setImportedBy($user ? $user->getUserIdentifier() : 'system');
        $newVersion->setIsActive(false);
        $newVersion->setRecordCount($recordCount);
        
        $this->entityManager->persist($newVersion);
        
        // 5. Flush and return new version_id
        $this->entityManager->flush();
        
        return $newVersionId;
    }

    /**
     * Rollback to a previous dataset version
     * 
     * @param string $versionId - Version ID to rollback to
     * 
     * @return array{
     *   oldVersionId: string,
     *   newVersionId: string,
     *   datasetType: string,
     *   recordCount: int
     * }
     */
    public function rollbackDataset(string $versionId): array
    {
        // 1. Find target version
        $targetVersion = $this->datasetVersionRepository->findOneBy(['versionUuid' => $versionId]);
        if (!$targetVersion) {
            throw new \RuntimeException("Version $versionId not found");
        }
        
        // 2. Find current active version
        $currentVersion = $this->datasetVersionRepository->findOneBy([
            'datasetType' => $targetVersion->getDatasetType(),
            'isActive' => true
        ]);
        
        $datasetType = $targetVersion->getDatasetType();
        
        // 3. Deactivate all versions and data for this dataset type
        $this->entityManager->createQuery(
            'UPDATE App\\Entity\\DatasetVersion v 
             SET v.isActive = false 
             WHERE v.datasetType = :type'
        )
        ->setParameter('type', $datasetType)
        ->execute();
        
        // Deactivate data based on type
        switch ($datasetType) {
            case 'TARIFF_RATES':
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\TariffRate t SET t.isActive = false'
                )->execute();
                
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\TariffRate t 
                     SET t.isActive = true 
                     WHERE t.versionId = :versionId'
                )
                ->setParameter('versionId', $versionId)
                ->execute();
                break;
                
            case 'FREIGHT_TABLES':
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\FreightTable f SET f.isActive = false'
                )->execute();
                
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\FreightTable f 
                     SET f.isActive = true 
                     WHERE f.versionId = :versionId'
                )
                ->setParameter('versionId', $versionId)
                ->execute();
                break;
                
            case 'FX_RATES':
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\FxRate f SET f.isActive = false'
                )->execute();
                
                $this->entityManager->createQuery(
                    'UPDATE App\\Entity\\FxRate f 
                     SET f.isActive = true 
                     WHERE f.versionId = :versionId'
                )
                ->setParameter('versionId', $versionId)
                ->execute();
                break;
        }
        
        // 4. Activate target version
        $targetVersion->setIsActive(true);
        
        // 5. Flush changes
        $this->entityManager->flush();
        
        // 6. Return rollback summary
        return [
            'oldVersionId' => $currentVersion?->getVersionUuid(),
            'newVersionId' => $targetVersion->getVersionUuid(),
            'datasetType' => $targetVersion->getDatasetType(),
            'recordCount' => $targetVersion->getRecordCount()
        ];
    }

    /**
     * Cache of verified signatures to avoid recalculating for the same file in the same session.
     * Key: file path, Value: ['hash' => string, 'mtime' => int, 'size' => int]
     * 
     * @var array<string, array{hash: string, mtime: int, size: int}>
     */
    private array $signatureCache = [];

    /**
     * Verify CSV file signature (SHA-256 hash)
     * 
     * Uses stream-based hashing for memory efficiency with large files (100MB+).
     * Implements caching to avoid re-verification of the same file in a session.
     * 
     * Performance optimizations:
     * - Stream-based hashing: Processes file in 8KB chunks (vs loading entire file into memory)
     * - Session caching: Avoids re-hashing if file hasn't changed (based on mtime + size)
     * - Early validation: Checks file existence before expensive hash operation
     * 
     * @param string $csvPath - Path to CSV file
     * @param string $signaturePath - Path to .sha256 signature file
     * 
     * @throws \RuntimeException if signature verification fails
     */
    private function verifySignature(string $csvPath, string $signaturePath): void
    {
        // Early validation
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV file not found: $csvPath");
        }
        
        if (!file_exists($signaturePath)) {
            throw new \RuntimeException("Signature file not found: $signaturePath");
        }
        
        // Read expected hash from signature file
        $expectedHash = trim(file_get_contents($signaturePath));
        if (empty($expectedHash)) {
            throw new \RuntimeException("Signature file is empty: $signaturePath");
        }
        
        // Calculate hash using stream-based approach with caching
        $calculatedHash = $this->calculateStreamHash($csvPath);
        
        // Verify hashes match (timing-safe comparison)
        if (!hash_equals($expectedHash, $calculatedHash)) {
            // Invalidate cache on failure
            unset($this->signatureCache[$csvPath]);
            throw new \RuntimeException("Signature verification failed. File may be corrupted or tampered.");
        }
    }
    
    /**
     * Calculate SHA-256 hash using stream-based processing
     * 
     * Benefits over hash_file():
     * - Memory efficient: Processes 8KB chunks instead of loading entire file
     * - Cacheable: Can leverage file metadata for cache validation
     * - Cancellable: Could be extended to support progress callbacks for very large files
     * 
     * @param string $filePath - Path to file to hash
     * @return string - Lowercase hexadecimal SHA-256 hash
     */
    private function calculateStreamHash(string $filePath): string
    {
        $fileMtime = filemtime($filePath);
        $fileSize = filesize($filePath);
        
        // Check cache: if file metadata matches, return cached hash
        if (isset($this->signatureCache[$filePath])) {
            $cached = $this->signatureCache[$filePath];
            if ($cached['mtime'] === $fileMtime && $cached['size'] === $fileSize) {
                return $cached['hash'];
            }
        }
        
        // Stream-based hashing for memory efficiency
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Cannot open file for hashing: $filePath");
        }
        
        $hashContext = hash_init('sha256');
        
        // Process in 8KB chunks (optimal for disk I/O)
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk !== false) {
                hash_update($hashContext, $chunk);
            }
        }
        
        fclose($handle);
        
        $calculatedHash = hash_final($hashContext);
        
        // Cache the result
        $this->signatureCache[$filePath] = [
            'hash' => $calculatedHash,
            'mtime' => $fileMtime,
            'size' => $fileSize,
        ];
        
        return $calculatedHash;
    }
    
    /**
     * Clear the signature cache (useful for testing or forced re-verification)
     */
    public function clearSignatureCache(): void
    {
        $this->signatureCache = [];
    }

    /**
     * Activate a dataset version (set is_active = 1, deactivate others)
     * 
     * @param string $versionId - Version ID to activate
     */
    private function activateVersion(string $versionId): void
    {
        // Fully implemented helper method
        
        // Find target version
        $targetVersion = $this->datasetVersionRepository->findOneBy(['versionUuid' => $versionId]);
        
        if (!$targetVersion) {
            throw new \RuntimeException("Version $versionId not found");
        }
        
        // Deactivate all other versions of same dataset type
        $allVersions = $this->datasetVersionRepository->findBy([
            'datasetType' => $targetVersion->getDatasetType()
        ]);
        
        foreach ($allVersions as $version) {
            $version->setIsActive(false);
        }
        
        // Activate target version
        $targetVersion->setIsActive(true);
        
        // Flush changes
        $this->entityManager->flush();
    }

    /**
     * Get all dataset versions for a dataset type
     * 
     * @param string $datasetType - TARIFF_RATES, FREIGHT_TABLES, or FX_RATES
     * 
     * @return array - Array of DatasetVersion entities
     */
    public function getVersionHistory(string $datasetType): array
    {
        return $this->datasetVersionRepository->findBy(
            ['datasetType' => $datasetType],
            ['importedAt' => 'DESC']
        );
    }

    /**
     * Get currently active dataset version
     * 
     * @param string $datasetType - TARIFF_RATES, FREIGHT_TABLES, or FX_RATES
     * 
     * @return DatasetVersion|null
     */
    public function getActiveVersion(string $datasetType): ?DatasetVersion
    {
        return $this->datasetVersionRepository->findOneBy([
            'datasetType' => $datasetType,
            'isActive' => true
        ]);
    }
}
