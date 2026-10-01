<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\SupplierPortal;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompanyRepository $companyRepository
    ) {}

    /**
     * Import companies from Tracker.xlsx
     */
    public function importFromTrackerExcel(string $filePath): array
    {
        $stats = [
            'companies_created' => 0,
            'companies_updated' => 0,
            'contacts_created' => 0,
            'errors' => [],
        ];

        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            // Get header row to map columns
            $headerRow = 1;
            $headers = [];
            foreach ($sheet->getRowIterator($headerRow, $headerRow) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                foreach ($cellIterator as $cell) {
                    $headers[] = $cell->getValue();
                }
            }

            // Map column indices
            $columnMap = $this->mapColumns($headers);

            // Process data rows
            foreach ($sheet->getRowIterator($headerRow + 1) as $row) {
                try {
                    $rowData = $this->extractRowData($row, $columnMap);
                    
                    if (empty($rowData['company_name'])) {
                        continue; // Skip empty rows
                    }

                    $company = $this->importCompany($rowData);
                    
                    if ($company->getId()) {
                        $stats['companies_updated']++;
                    } else {
                        $stats['companies_created']++;
                    }

                    // Import contact if exists
                    if (!empty($rowData['contact_name']) || !empty($rowData['contact_email'])) {
                        $this->importContact($company, $rowData);
                        $stats['contacts_created']++;
                    }

                    // Import supplier portal status
                    if (isset($rowData['portal_registered'])) {
                        $this->importSupplierPortal($company, $rowData);
                    }

                } catch (\Exception $e) {
                    $stats['errors'][] = "Row {$row->getRowIndex()}: " . $e->getMessage();
                }
            }

            $this->entityManager->flush();

        } catch (\Exception $e) {
            $stats['errors'][] = "File error: " . $e->getMessage();
        }

        return $stats;
    }

    /**
     * Map column headers to field names
     */
    private function mapColumns(array $headers): array
    {
        $map = [];
        
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            
            $map[$index] = match(true) {
                str_contains($normalized, 'company') && str_contains($normalized, 'name') => 'company_name',
                str_contains($normalized, 'sector') => 'sector',
                str_contains($normalized, 'tier') => 'account_tier',
                str_contains($normalized, 'stage') => 'pipeline_stage',
                str_contains($normalized, 'region') => 'region',
                str_contains($normalized, 'website') => 'website',
                str_contains($normalized, 'google') || str_contains($normalized, 'drive') => 'google_drive',
                str_contains($normalized, 'contact') && str_contains($normalized, 'name') => 'contact_name',
                str_contains($normalized, 'contact') && str_contains($normalized, 'email') => 'contact_email',
                str_contains($normalized, 'contact') && str_contains($normalized, 'phone') => 'contact_phone',
                str_contains($normalized, 'contact') && str_contains($normalized, 'role') => 'contact_role',
                str_contains($normalized, 'portal') && str_contains($normalized, 'registered') => 'portal_registered',
                str_contains($normalized, 'portal') && str_contains($normalized, 'date') => 'portal_signup_date',
                str_contains($normalized, 'priority') => 'priority',
                str_contains($normalized, 'status') => 'status',
                default => null,
            };
        }

        return array_filter($map);
    }

    /**
     * Extract row data based on column map
     */
    private function extractRowData($row, array $columnMap): array
    {
        $data = [];
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        $colIndex = 0;
        foreach ($cellIterator as $cell) {
            if (isset($columnMap[$colIndex])) {
                $fieldName = $columnMap[$colIndex];
                $data[$fieldName] = $cell->getValue();
            }
            $colIndex++;
        }

        return $data;
    }

    /**
     * Import or update company
     */
    private function importCompany(array $data): Company
    {
        $companyName = trim($data['company_name']);
        
        // Check if company exists
        $company = $this->companyRepository->findOneBy(['name' => $companyName]);
        
        if (!$company) {
            $company = new Company();
            $company->setName($companyName);
        }

        // Update fields
        if (!empty($data['sector'])) {
            $company->setSector($data['sector']);
        }
        if (!empty($data['account_tier'])) {
            $company->setAccountTier($data['account_tier']);
        }
        if (!empty($data['pipeline_stage'])) {
            $company->setPipelineStage($data['pipeline_stage']);
        }
        if (!empty($data['region'])) {
            $company->setRegion($data['region']);
        }
        if (!empty($data['website'])) {
            $company->setWebsite($data['website']);
        }
        if (!empty($data['google_drive'])) {
            $company->setGoogleDriveLink($data['google_drive']);
        }
        // Company's real model: pipelineStage (prospecting priority) and
        // companyStatus — no phantom priority/status setters.
        if (isset($data['priority']) && in_array($data['priority'], \App\Entity\Company::VALID_STAGES, true)) {
            $company->setPipelineStage($data['priority']);
        }
        if (isset($data['status']) && in_array($data['status'], \App\Entity\Company::VALID_STATUSES, true)) {
            $company->setCompanyStatus($data['status']);
        }

        $this->entityManager->persist($company);
        return $company;
    }

    /**
     * Import contact for company
     *
     * Deduplicates by (email + company): re-importing the same Tracker file
     * updates the existing contact instead of creating a duplicate row.
     * Only non-empty fields are written, so existing data is preserved.
     */
    private function importContact(Company $company, array $data): ?Contact
    {
        if (empty($data['contact_email'])) {
            return null;
        }

        $email = trim($data['contact_email']);
        if ($email === '') {
            return null;
        }

        $contact = $this->entityManager->getRepository(Contact::class)
            ->findOneBy(['email' => $email, 'company' => $company]);

        if (!$contact) {
            $contact = new Contact();
            $contact->setCompany($company);
            $contact->setEmail($email);
            $this->entityManager->persist($contact);
        }

        if (!empty($data['contact_name'])) {
            $nameParts = explode(' ', $data['contact_name'], 2);
            $contact->setFirstName($nameParts[0]);
            $contact->setLastName($nameParts[1] ?? '');
        }
        if (!empty($data['contact_phone'])) {
            $contact->setPhone($data['contact_phone']);
        }
        if (!empty($data['contact_role'])) {
            $contact->setJobTitle($data['contact_role']);
        }

        return $contact;
    }

    /**
     * Import supplier portal status
     */
    private function importSupplierPortal(Company $company, array $data): void
    {
        $portal = $company->getSupplierPortal();
        
        if (!$portal) {
            $portal = new SupplierPortal();
            $portal->setCompany($company);
        }

        $registered = strtolower($data['portal_registered'] ?? '') === 'yes';
        $portal->setRegistered($registered);

        if ($registered && !empty($data['portal_signup_date'])) {
            try {
                $signupDate = new \DateTime($data['portal_signup_date']);
                $portal->setRegistrationDate($signupDate);
            } catch (\Exception $e) {
                // Invalid date format, skip
            }
        }

        $this->entityManager->persist($portal);
    }
}
