<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\Activity;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * Export Service
 * 
 * Provides data export functionality in multiple formats (CSV, Excel)
 */
class ExportService
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    /**
     * Export companies to file
     *
     * @param list<Company> $companies
     */
    public function exportCompanies(array $companies, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'Company Name', 'Sector', 'City', 'Country', 'Website',
            'Account Tier', 'Pipeline Stage', 'Region', 'Created At'
        ];
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        $row = 2;
        foreach ($companies as $company) {
            $data = [
                $company->getId(),
                $company->getName(),
                $company->getSector(),
                $company->getCity(),
                $company->getCountry(),
                $company->getWebsite(),
                $company->getAccountTier(),
                $company->getPipelineStage(),
                $company->getRegion(),
                $company->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'companies', $format);
    }

    /**
     * Export discovered companies with enrichment data (contacts, addresses, LinkedIn)
     *
     * @param list<Company> $companies
     */
    public function exportDiscoveredCompanies(array $companies, string $format = 'xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Discovered Companies');

        // Rich headers with all webcrawler data
        $headers = [
            'Company Name', 'Sector', 'Region', 'Country', 'City', 'Address',
            'Website', 'LinkedIn', 'Phone', 'Status', 'Discovered Date',
            'Contact 1 Name', 'Contact 1 Title', 'Contact 1 Email', 'Contact 1 LinkedIn',
            'Contact 2 Name', 'Contact 2 Title', 'Contact 2 Email', 'Contact 2 LinkedIn',
            'Contact 3 Name', 'Contact 3 Title', 'Contact 3 Email', 'Contact 3 LinkedIn',
        ];
        $sheet->fromArray($headers, null, 'A1');

        // Style header row
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1a1a2e']],
        ];
        $sheet->getStyle('A1:W1')->applyFromArray($headerStyle);

        // Add data
        $row = 2;
        foreach ($companies as $company) {
            $contacts = $company->getContacts()->toArray();

            $data = [
                $company->getName(),
                $company->getSector(),
                $company->getRegion(),
                $company->getCountry(),
                $company->getCity(),
                $company->getAddress(),
                $company->getWebsite(),
                $company->getLinkedinCompanyUrl(),
                method_exists($company, 'getPhone') ? $company->getPhone() : null,
                ucfirst($company->getCompanyStatus()),
                $company->getCreatedAt()?->format('Y-m-d'),
            ];

            // Add up to 3 contacts
            for ($i = 0; $i < 3; $i++) {
                if (isset($contacts[$i])) {
                    $c = $contacts[$i];
                    $data[] = ($c->getFirstName() ?? '') . ' ' . ($c->getLastName() ?? '');
                    $data[] = $c->getJobTitle();
                    $data[] = $c->getEmail();
                    $data[] = $c->getLinkedInUrl();
                } else {
                    $data[] = null;
                    $data[] = null;
                    $data[] = null;
                    $data[] = null;
                }
            }

            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'W') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->writeToFile($spreadsheet, 'discovered_companies', $format);
    }

    /**
     * Export contacts to file
     *
     * @param list<Contact> $contacts
     */
    public function exportContacts(array $contacts, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Role',
            'Company', 'Created At'
        ];
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        $row = 2;
        foreach ($contacts as $contact) {
            $data = [
                $contact->getId(),
                $contact->getFirstName(),
                $contact->getLastName(),
                $contact->getEmail(),
                $contact->getPhone(),
                $contact->getRole(),
                $contact->getCompany()?->getName(),
                $contact->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'contacts', $format);
    }

    /**
     * Export leads to file
     *
     * @param list<Lead> $leads
     */
    public function exportLeads(array $leads, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'Company Name', 'Contact Name', 'Contact Email', 'Source',
            'Status', 'Score', 'Assigned To', 'Created At'
        ];
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        // NB: maps the intended columns onto the REAL App\Entity\Lead
        // accessors. The previous implementation called getContactName(),
        // getContactEmail(), getStatus(), getScore() and getAssignedTo(),
        // none of which exist on Lead — the first call would have crashed
        // with "undefined method". Contact name has no Lead equivalent
        // (empty); public contact emails are joined for 'Contact Email'.
        $row = 2;
        foreach ($leads as $lead) {
            // contactEmailsPublic is a list of email strings (see Lead::$contactEmailsPublic).
            /** @var list<string> $contactEmails */
            $contactEmails = $lead->getContactEmailsPublic() ?? [];
            $data = [
                $lead->getId(),
                $lead->getCompanyName(),
                '',
                implode(', ', $contactEmails),
                $lead->getSource(),
                $lead->getReviewStatus(),
                $lead->getLeadScore(),
                $lead->getOwnerRep(),
                $lead->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'leads', $format);
    }

    /**
     * Export activities to file
     *
     * @param list<Activity> $activities
     */
    public function exportActivities(array $activities, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'Type', 'Subject', 'Description', 'Company', 'Contact',
            'User', 'Activity Date', 'Created At'
        ];
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        $row = 2;
        foreach ($activities as $activity) {
            $activityContact = $activity->getContact();
            $data = [
                $activity->getId(),
                $activity->getType(),
                $activity->getSubject(),
                $activity->getDescription(),
                $activity->getCompany()?->getName(),
                $activityContact !== null ? $activityContact->getFirstName() . ' ' . $activityContact->getLastName() : '',
                $activity->getUser()?->getEmail(),
                $activity->getActivityDate()?->format('Y-m-d H:i:s'),
                $activity->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'activities', $format);
    }

    /**
     * Write spreadsheet to temporary file
     */
    private function writeToFile(Spreadsheet $spreadsheet, string $prefix, string $format): string
    {
        $timestamp = date('Y-m-d_His');
        $extension = $format === 'xlsx' ? 'xlsx' : 'csv';
        $filename = "{$prefix}_{$timestamp}.{$extension}";
        $filepath = sys_get_temp_dir() . '/' . $filename;

        // Spreadsheet-formula neutralization: CRM/discovery-sourced strings
        // (company names, notes, emails-as-text, ...) starting with =, +, -,
        // @, TAB or CR would execute as formulas in Excel/LibreOffice when
        // the exported file is opened. Prefixing an apostrophe forces the
        // value to be treated as text.
        $this->neutralizeSpreadsheetFormulas($spreadsheet);

        if ($format === 'xlsx') {
            $writer = new Xlsx($spreadsheet);
        } else {
            $writer = new Csv($spreadsheet);
        }

        $writer->save($filepath);

        return $filepath;
    }

    private function neutralizeSpreadsheetFormulas(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->getValue();

                    if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
                        $cell->setValue("'" . $value);
                    }
                }
            }
        }
    }

    /**
     * Export generic entity data
     *
     * The entity class and getter names are caller-supplied, so both are
     * validated before use:
     *  - entityClass must be a known App\Entity class
     *  - getters must be real methods matching the ^get[A-Z] pattern
     * (callers currently: none in-repo — kept as a safe generic helper)
     *
     * @param array<string|int, mixed> $fields Map of column label => getter name
     */
    public function exportGeneric(string $entityClass, array $fields, string $filename, string $format = 'csv'): string
    {
        // Guard 1: entity class must be a known entity
        if (!class_exists($entityClass) || !str_starts_with($entityClass, 'App\Entity\\')) {
            throw new \InvalidArgumentException("Unknown entity class: {$entityClass}");
        }
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Get all entities
        $entities = $this->em->getRepository($entityClass)->findAll();
        
        // Set headers
        $sheet->fromArray(array_keys($fields), null, 'A1');
        
        // Add data
        $row = 2;
        foreach ($entities as $entity) {
            $data = [];
            foreach ($fields as $fieldName => $getter) {
                // Guard 2: getter must be a real accessor (^get[A-Z]...).
                // Arbitrary method invocation from caller-supplied strings would
                // otherwise be a method-injection risk if ever exposed.
                if (!is_string($getter) || !preg_match('/^get[A-Z][A-Za-z0-9]*$/', $getter) || !method_exists($entity, $getter)) {
                    $getterLabel = is_string($getter) ? $getter : get_debug_type($getter);
                    throw new \InvalidArgumentException(
                        "Invalid getter '{$getterLabel}' for field '{$fieldName}' on {$entityClass}"
                    );
                }
                $value = $entity->$getter();
                
                // Convert objects to strings
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $value = (string) $value;
                } elseif (is_object($value)) {
                    $value = get_class($value);
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }
                
                $data[] = $value;
            }
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, $filename, $format);
    }
}
