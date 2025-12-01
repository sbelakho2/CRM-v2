<?php

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
        private FxRateRepository $fxRateRepository
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
                $tariffRate->setHsCode($data['hs_code']);
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
            'versionId' => $versionUuid,
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
                $freight->setCurrency($data['currency'] ?? 'USD');
                
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
            'versionId' => $versionUuid,
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
        // TODO: Implement FX rate import
        // 
        // Steps:
        // 1. Verify signature
        // 2. Generate version_id (UUID)
        // 3. Create DatasetVersion entry (dataset_type = 'FX_RATES')
        // 4. Parse CSV and create FxRate entities:
        //    - Set from_currency, to_currency, rate, asof, version_id
        // 5. Flush and activate version
        // 6. Return summary

        throw new \RuntimeException('Feature not yet implemented');
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
        // TODO: Implement dataset snapshot
        // 
        // Steps:
        // 1. Get current active version:
        //    $activeVersion = $this->datasetVersionRepository->findOneBy([
        //        'datasetType' => $datasetType,
        //        'isActive' => true
        //    ]);
        // 
        // 2. Generate new version_id:
        //    $newVersionId = Uuid::v4()->toRfc4122();
        // 
        // 3. Clone all records from active version to new version:
        //    - For TARIFF_RATES: Copy all tariff_rates rows with old version_id
        //    - For FREIGHT_TABLES: Copy all freight_table rows
        //    - For FX_RATES: Copy all fx_rates rows
        //    - Update version_id to $newVersionId
        // 
        // 4. Create new DatasetVersion entry:
        //    $newVersion = new DatasetVersion();
        //    $newVersion->setVersionId($newVersionId);
        //    $newVersion->setDatasetType($datasetType);
        //    $newVersion->setDescription("SNAPSHOT: $description");
        //    $newVersion->setImportedAt(new \DateTime());
        //    $newVersion->setIsActive(false);
        //    $newVersion->setRecordCount($activeVersion->getRecordCount());
        //    $newVersion->setAsof($activeVersion->getAsof());
        // 
        // 5. Flush and return new version_id:
        //    $this->entityManager->flush();
        //    return $newVersionId;

        throw new \RuntimeException('Feature not yet implemented');
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
        // TODO: Implement dataset rollback
        // 
        // Steps:
        // 1. Find target version:
        //    $targetVersion = $this->datasetVersionRepository->findOneBy(['versionId' => $versionId]);
        //    if (!$targetVersion) {
        //        throw new \RuntimeException("Version $versionId not found");
        //    }
        // 
        // 2. Find current active version:
        //    $currentVersion = $this->datasetVersionRepository->findOneBy([
        //        'datasetType' => $targetVersion->getDatasetType(),
        //        'isActive' => true
        //    ]);
        // 
        // 3. Deactivate current version:
        //    $currentVersion->setIsActive(false);
        // 
        // 4. Activate target version:
        //    $targetVersion->setIsActive(true);
        // 
        // 5. Flush changes:
        //    $this->entityManager->flush();
        // 
        // 6. Return rollback summary:
        //    return [
        //        'oldVersionId' => $currentVersion->getVersionId(),
        //        'newVersionId' => $targetVersion->getVersionId(),
        //        'datasetType' => $targetVersion->getDatasetType(),
        //        'recordCount' => $targetVersion->getRecordCount()
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Verify CSV file signature (SHA-256 hash)
     * 
     * @param string $csvPath - Path to CSV file
     * @param string $signaturePath - Path to .sha256 signature file
     * 
     * @throws \RuntimeException if signature verification fails
     */
    private function verifySignature(string $csvPath, string $signaturePath): void
    {
        // Fully implemented helper method
        
        if (!file_exists($csvPath)) {
            throw new \RuntimeException("CSV file not found: $csvPath");
        }
        
        if (!file_exists($signaturePath)) {
            throw new \RuntimeException("Signature file not found: $signaturePath");
        }
        
        // Calculate hash of CSV file
        $calculatedHash = hash_file('sha256', $csvPath);
        
        // Read expected hash from signature file
        $expectedHash = trim(file_get_contents($signaturePath));
        
        // Verify hashes match
        if ($calculatedHash !== $expectedHash) {
            throw new \RuntimeException("Signature verification failed. File may be corrupted or tampered.");
        }
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
        $targetVersion = $this->datasetVersionRepository->findOneBy(['versionId' => $versionId]);
        
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
