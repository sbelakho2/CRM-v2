<?php

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
     */
    public function exportCompanies(array $companies, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'Company Name', 'Sector', 'Location', 'Website', 'LinkedIn',
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
                $company->getLocation(),
                $company->getWebsite(),
                $company->getLinkedin(),
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
     * Export contacts to file
     */
    public function exportContacts(array $contacts, string $format = 'csv'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = [
            'ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Role',
            'Company', 'LinkedIn', 'Created At'
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
                $contact->getLinkedin(),
                $contact->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'contacts', $format);
    }

    /**
     * Export leads to file
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
        $row = 2;
        foreach ($leads as $lead) {
            $data = [
                $lead->getId(),
                $lead->getCompanyName(),
                $lead->getContactName(),
                $lead->getContactEmail(),
                $lead->getSource(),
                $lead->getStatus(),
                $lead->getScore(),
                $lead->getAssignedTo()?->getEmail(),
                $lead->getCreatedAt()?->format('Y-m-d H:i:s'),
            ];
            $sheet->fromArray($data, null, 'A' . $row);
            $row++;
        }
        
        return $this->writeToFile($spreadsheet, 'leads', $format);
    }

    /**
     * Export activities to file
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
            $data = [
                $activity->getId(),
                $activity->getType(),
                $activity->getSubject(),
                $activity->getDescription(),
                $activity->getCompany()?->getName(),
                $activity->getContact() ? $activity->getContact()->getFirstName() . ' ' . $activity->getContact()->getLastName() : '',
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
        
        if ($format === 'xlsx') {
            $writer = new Xlsx($spreadsheet);
        } else {
            $writer = new Csv($spreadsheet);
        }
        
        $writer->save($filepath);
        
        return $filepath;
    }

    /**
     * Export generic entity data
     */
    public function exportGeneric(string $entityClass, array $fields, string $filename, string $format = 'csv'): string
    {
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
                $value = call_user_func([$entity, $getter]);
                
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
