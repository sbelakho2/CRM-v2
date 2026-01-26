<?php

namespace App\Service\Import;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\SupplierPortal;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to import data from Tracker.xlsx into the CRM database
 * Uses league/csv for CSV parsing (converted from Excel)
 */
class TrackerImportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Import companies from Tracker.xlsx
     * Expects CSV format with headers in first row
     */
    public function importFromCsv(string $csvFilePath): array
    {
        if (!file_exists($csvFilePath)) {
            throw new \InvalidArgumentException("File not found: {$csvFilePath}");
        }

        $stats = [
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        $handle = fopen($csvFilePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Could not open file: {$csvFilePath}");
        }

        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new \RuntimeException("Could not read headers from CSV");
        }

        $this->logger->info("Starting Tracker import", [
            'file' => $csvFilePath,
            'columns' => count($headers)
        ]);

        // Process each row
        $rowNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $stats['processed']++;

            try {
                // Map CSV row to array with headers as keys
                $row = array_combine($headers, $data);
                
                // Skip empty rows
                if (empty($row['Company']) && empty($row['Company Name']) && empty($row['company_name'])) {
                    $stats['skipped']++;
                    continue;
                }

                // Import company
                $result = $this->importCompanyRow($row);
                
                if ($result === 'imported') {
                    $stats['imported']++;
                } elseif ($result === 'updated') {
                    $stats['updated']++;
                }

                // Flush every 50 records to avoid memory issues
                if ($stats['processed'] % 50 === 0) {
                    $this->em->flush();
                    $this->em->clear();
                    $this->logger->info("Progress update", $stats);
                }

            } catch (\Exception $e) {
                $error = "Row {$rowNumber}: " . $e->getMessage();
                $stats['errors'][] = $error;
                $this->logger->error($error);
            }
        }

        fclose($handle);

        // Final flush
        $this->em->flush();

        $this->logger->info("Tracker import completed", $stats);

        return $stats;
    }

    /**
     * Import a single company row from CSV
     */
    private function importCompanyRow(array $row): string
    {
        // Normalize column names (support different variations from actual Tracker.xlsx)
        $companyName = $row['Company'] 
            ?? $row['Company Name'] 
            ?? $row['company_name'] 
            ?? $row['name'] 
            ?? null;

        if (!$companyName) {
            throw new \InvalidArgumentException("Company name is required");
        }

        // Check if company already exists
        $company = $this->companyRepo->findOneBy(['name' => $companyName]);
        $isNew = false;

        if (!$company) {
            $company = new Company();
            $company->setName($companyName);
            $company->setCreatedAt(new \DateTime());
            $isNew = true;
        }

        // Map common column variations from actual Tracker.xlsx format
        $sector = $row['Sector'] ?? $row['sector'] ?? $row['Industry'] ?? null;
        $location = $row['Physical Site (Morocco) / Region'] ?? $row['Location'] ?? $row['location'] ?? $row['Physical Site'] ?? $row['Region'] ?? null;
        $website = $row['Website (Verified)'] ?? $row['Website'] ?? $row['website'] ?? $row['URL'] ?? null;
        $tier = $row['Priority (A/B/C)'] ?? $row['Priority'] ?? $row['Tier'] ?? $row['tier'] ?? $row['Account Tier'] ?? null;
        $stage = $row['Status'] ?? $row['Stage'] ?? $row['stage'] ?? $row['Pipeline Stage'] ?? null;
        $portalUrl = $row['Contact URL / Supplier Portal'] ?? $row['Portal URL'] ?? $row['portal_url'] ?? $row['Supplier Portal'] ?? null;
        $notes = $row['Source / Notes'] ?? $row['Notes'] ?? $row['notes'] ?? $row['Source Notes'] ?? null;

        // Set company data
        if ($sector) {
            $company->setSector($sector);
        } elseif ($isNew) {
            // Set default sector for new companies if not provided
            $company->setSector('General Manufacturing');
        }

        if ($location) {
            $company->setPhysicalSite($location);
        }

        if ($website) {
            $company->setWebsite($website);
        }

        if ($tier) {
            $company->setAccountTier(strtoupper(substr($tier, 0, 1))); // A, B, or C
        }

        if ($stage) {
            $company->setPipelineStage($stage);
        } else {
            $company->setPipelineStage('Prospect'); // Default
        }

        if ($notes) {
            $existingNotes = $company->getSourceNotes();
            if ($existingNotes) {
                $company->setSourceNotes($existingNotes . "\n\nImported from Tracker: " . $notes);
            } else {
                $company->setSourceNotes("Imported from Tracker.xlsx: " . $notes);
            }
        } elseif ($isNew) {
            $company->setSourceNotes("Imported from Tracker.xlsx on " . date('Y-m-d'));
        }

        $company->setUpdatedAt(new \DateTime());

        $this->em->persist($company);

        // Handle portal information if provided
        if ($portalUrl) {
            $this->importPortalInfo($company, $portalUrl, $row);
        }

        return $isNew ? 'imported' : 'updated';
    }

    /**
     * Import or update supplier portal information
     */
    private function importPortalInfo(Company $company, string $portalUrl, array $row): void
    {
        $portal = $company->getSupplierPortal();
        
        if (!$portal) {
            $portal = new SupplierPortal();
            $portal->setCompany($company);
        }

        $portal->setPortalUrl($portalUrl);

        // Check if registered
        $registered = $row['Portal Registered'] ?? $row['Registered'] ?? null;
        if ($registered && in_array(strtolower($registered), ['yes', 'true', '1', 'registered'])) {
            $portal->setRegistered(true);
        }

        // Portal ID
        $portalId = $row['Portal ID'] ?? $row['Account ID'] ?? null;
        if ($portalId) {
            $portal->setPortalId($portalId);
        }

        // Dates
        $submittedDate = $row['Submitted Date'] ?? $row['Portal Submitted'] ?? null;
        if ($submittedDate) {
            try {
                $portal->setSubmittedDate(new \DateTime($submittedDate));
            } catch (\Exception $e) {
                $this->logger->warning("Invalid submitted date", ['date' => $submittedDate]);
            }
        }

        $approvalDate = $row['Approval Date'] ?? $row['Portal Approved'] ?? null;
        if ($approvalDate) {
            try {
                $portal->setApprovalDate(new \DateTime($approvalDate));
            } catch (\Exception $e) {
                $this->logger->warning("Invalid approval date", ['date' => $approvalDate]);
            }
        }

        // Buyer contact
        $buyerName = $row['Buyer Name'] ?? $row['Portal Contact'] ?? null;
        if ($buyerName) {
            $portal->setBuyerName($buyerName);
        }

        $buyerEmail = $row['Buyer Email'] ?? $row['Portal Contact Email'] ?? null;
        if ($buyerEmail) {
            $portal->setBuyerEmail($buyerEmail);
        }

        $this->em->persist($portal);
    }

    /**
     * Generate CSV template for manual data entry
     */
    public function generateTemplate(string $outputPath): void
    {
        $headers = [
            'Company Name',
            'Sector',
            'Location',
            'Website',
            'Account Tier',
            'Pipeline Stage',
            'Portal URL',
            'Portal Registered',
            'Portal ID',
            'Submitted Date',
            'Approval Date',
            'Buyer Name',
            'Buyer Email',
            'Notes'
        ];

        $handle = fopen($outputPath, 'w');
        fputcsv($handle, $headers);
        
        // Add example row
        $example = [
            'Example Company Ltd',
            'Automotive',
            'Tanger Free Zone',
            'https://example.com',
            'A',
            'SQL',
            'https://example.com/suppliers',
            'Yes',
            'SUPP-001',
            '2024-01-15',
            '2024-02-01',
            'John Doe',
            'john.doe@example.com',
            'Tier 1 automotive supplier'
        ];
        fputcsv($handle, $example);
        
        fclose($handle);

        $this->logger->info("Template generated", ['path' => $outputPath]);
    }
}
