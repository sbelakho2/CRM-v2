<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\BomLine;
use App\Repository\LeadRepository;
use App\Repository\QuoteRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Psr\Log\LoggerInterface;

/**
 * CSV Export Service
 * 
 * Provides CSV export functionality for:
 * - Enriched Lead lists with contact info
 * - Quote BOM data with pricing and confidence
 * - Part sourcing reports with alternatives
 * 
 * Supports filtering, custom field selection, and streaming for large datasets.
 */
class CsvExportService
{
    public function __construct(
        private LeadRepository $leadRepository,
        private QuoteRepository $quoteRepository,
        /** Reserved for export auditing; not read yet. */
        protected LoggerInterface $logger
    ) {}

    /**
     * Export leads to CSV as a streamed response
     * 
     * @param array<string, mixed> $filters Optional filters: status, hasEmails, sector, region, dateFrom, dateTo
     * @param list<string>|null $fields Custom field selection (null = all fields)
     */
    public function exportLeads(array $filters = [], ?array $fields = null): StreamedResponse
    {
        $response = new StreamedResponse(function() use ($filters, $fields) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }

            // Define available fields
            $availableFields = [
                'id' => 'ID',
                'company_name' => 'Company Name',
                'legal_name' => 'Legal Name',
                'website' => 'Website',
                'lead_url' => 'Lead URL',
                'region' => 'Region',
                'site_location' => 'Site Location',
                'us_state' => 'US State',
                'us_city_metro' => 'US City/Metro',
                'sectors' => 'Sectors',
                'fit_signals' => 'Fit Signals',
                'morocco_signal' => 'Morocco Signal',
                'quality_stack' => 'Quality Stack',
                'emails' => 'Email Addresses',
                'has_contact_form' => 'Has Contact Form',
                'contact_form_url' => 'Contact Form URL',
                'supplier_portal_url' => 'Supplier Portal URL',
                'rfq_page_url' => 'RFQ/RFP Page URL',
                'defense_flag' => 'Defense Flag',
                'lead_score' => 'Lead Score',
                'review_status' => 'Review Status',
                'scraping_method' => 'Scraping Method',
                'pages_scraped' => 'Pages Scraped',
                'last_scraped_at' => 'Last Scraped',
                'notes' => 'Auto Notes',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ];
            
            // Use specified fields or all. Unknown ?fields= values are
            // DROPPED — they previously became user-controlled header strings
            // with permanently blank columns.
            $exportFields = $fields !== null
                ? array_values(array_intersect($fields, array_keys($availableFields)))
                : array_keys($availableFields);
            if ($exportFields === []) {
                $exportFields = array_keys($availableFields);
            }
            
            // Write header row (labels come from the whitelist, never input)
            $headers = array_map(fn($f) => $availableFields[$f] ?? $f, $exportFields);
            fputcsv($handle, $headers, ',', '"', '\\');
            
            // Build query
            $qb = $this->leadRepository->createQueryBuilder('l');
            
            // Apply filters
            if (!empty($filters['status'])) {
                $qb->andWhere('l.reviewStatus = :status')
                   ->setParameter('status', $filters['status']);
            }
            
            if (isset($filters['hasEmails']) && $filters['hasEmails']) {
                $qb->andWhere('l.contactEmailsPublic IS NOT NULL')
                   ->andWhere('l.contactEmailsPublic != :empty')
                   ->setParameter('empty', '[]');
            }
            
            if (isset($filters['hasContactForm']) && $filters['hasContactForm']) {
                $qb->andWhere('l.hasContactForm = true');
            }
            
            if (!empty($filters['sector']) && is_string($filters['sector'])) {
                $qb->andWhere('l.sectorTags LIKE :sector')
                   ->setParameter('sector', '%' . $filters['sector'] . '%');
            }

            if (!empty($filters['region'])) {
                $qb->andWhere('l.regionTag = :region')
                   ->setParameter('region', $filters['region']);
            }

            if (!empty($filters['dateFrom']) && is_string($filters['dateFrom'])) {
                $qb->andWhere('l.createdAt >= :dateFrom')
                   ->setParameter('dateFrom', new \DateTime($filters['dateFrom']));
            }

            if (!empty($filters['dateTo']) && is_string($filters['dateTo'])) {
                $qb->andWhere('l.createdAt <= :dateTo')
                   ->setParameter('dateTo', new \DateTime($filters['dateTo']));
            }

            $qb->orderBy('l.createdAt', 'DESC');

            // Stream results
            /** @var iterable<Lead> $leads */
            $leads = $qb->getQuery()->toIterable();
            
            foreach ($leads as $lead) {
                $row = $this->leadToRow($lead, $exportFields);
                fputcsv($handle, self::csvRow($row), ',', '"', '\\');
            }
            
            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="leads_export_' . date('Y-m-d_His') . '.csv"');
        
        return $response;
    }

    /**
     * Export quote BOM to CSV
     * 
     * @param Quote $quote The quote to export
     * @param bool $includeAlternatives Include alternative parts
     * @param bool $includeConfidence Include confidence details
     */
    public function exportQuoteBom(Quote $quote, bool $includeAlternatives = true, bool $includeConfidence = true): StreamedResponse
    {
        $response = new StreamedResponse(function() use ($quote, $includeAlternatives, $includeConfidence) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }

            // Build headers
            $headers = [
                'Line #', 'MPN', 'Matched MPN', 'Original MPN', 'Manufacturer', 'Description',
                'Quantity', 'Unit Price', 'Manual Price', 'Extended Price', 'Source',
                'Stock', 'Lead Time (Days)', 'Availability', 'Status'
            ];
            
            if ($includeConfidence) {
                $headers = array_merge($headers, [
                    'Confidence Score', 'Confidence Level', 'Requires Review',
                    'Lifecycle Status', 'Lifecycle Warning', 'Confidence Reasons', 'Confidence Warnings'
                ]);
            }
            
            if ($includeAlternatives) {
                $headers = array_merge($headers, [
                    'Alt 1 MPN', 'Alt 1 Price', 'Alt 1 Stock',
                    'Alt 2 MPN', 'Alt 2 Price', 'Alt 2 Stock',
                    'Alt 3 MPN', 'Alt 3 Price', 'Alt 3 Stock'
                ]);
            }
            
            $headers[] = 'Supplier Name';
            $headers[] = 'Supplier Product URL';
            $headers[] = 'Search URL';
            $headers[] = 'Price Source URL';
            $headers[] = 'Notes';
            
            fputcsv($handle, $headers, ',', '"', '\\');
            
            // Export BOM lines
            foreach ($quote->getBomLines() as $line) {
                $row = [
                    $line->getLineNumber(),
                    $line->getMpn(),
                    $line->getMatchedMpn(),
                    $line->getOriginalMpn(),
                    $line->getManufacturer(),
                    $line->getDescription(),
                    $line->getQuantity(),
                    $line->getUnitPrice(),
                    $line->getManualUnitPrice(),
                    $line->getEffectiveExtendedPrice(),
                    $line->getProcurementSource(),
                    $line->getStock() ?? '',
                    $line->getLeadTimeDays(),
                    $line->getAvailability(),
                    $this->getLineStatus($line),
                ];
                
                if ($includeConfidence) {
                    $row = array_merge($row, [
                        $line->getConfidenceScore(),
                        $line->getConfidenceLevel(),
                        $line->requiresReview() ? 'Yes' : 'No',
                        $line->getLifecycleStatus(),
                        $line->getLifecycleWarning(),
                        implode('; ', $line->getConfidenceReasons() ?? []),
                        implode('; ', $line->getConfidenceWarnings() ?? []),
                    ]);
                }

                if ($includeAlternatives) {
                    $alts = $line->getAlternativeParts() ?? [];
                    for ($i = 0; $i < 3; $i++) {
                        $alt = $alts[$i] ?? null;
                        if (is_array($alt)) {
                            $row[] = $alt['mpn'] ?? '';
                            $row[] = $alt['price'] ?? '';
                            $row[] = $alt['stock'] ?? '';
                        } else {
                            $row[] = '';
                            $row[] = '';
                            $row[] = '';
                        }
                    }
                }
                
                $row[] = $line->getSupplierName();
                $row[] = $line->getSupplierProductUrl();
                $row[] = $line->getDistributorSearchUrl();
                $row[] = $line->getPriceSourceUrl();
                $row[] = $line->getManualNotes();
                
                fputcsv($handle, self::csvRow($row), ',', '"', '\\');
            }

            // Add summary rows
            fputcsv($handle, [], ',', '"', '\\'); // Empty row
            fputcsv($handle, ['Summary'], ',', '"', '\\');
            fputcsv($handle, ['Total Lines', count($quote->getBomLines())], ',', '"', '\\');
            fputcsv($handle, ['Total Cost', $quote->getTotalCost()], ',', '"', '\\');
            fputcsv($handle, ['Coverage %', $quote->getCoveragePercent()], ',', '"', '\\');
            fputcsv($handle, ['Quote Number', $quote->getQuoteNumber()], ',', '"', '\\');
            fputcsv($handle, ['Status', $quote->getStatus()], ',', '"', '\\');
            fputcsv($handle, ['Exported', date('Y-m-d H:i:s')], ',', '"', '\\');
            
            fclose($handle);
        });
        
        $quoteNum = $quote->getQuoteNumber() ?? $quote->getId();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"quote_{$quoteNum}_bom_export.csv\"");
        
        return $response;
    }

    /**
     * Export part sourcing report (multi-quote comparison)
     * @param list<int> $quoteIds
     */
    public function exportSourcingReport(array $quoteIds): StreamedResponse
    {
        $response = new StreamedResponse(function() use ($quoteIds) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }

            $headers = [
                'Quote', 'Line #', 'MPN', 'Manufacturer', 'Quantity',
                'Source', 'Unit Price', 'Extended Price', 'Confidence',
                'Lifecycle Warning', 'Has Alternatives', 'Alt Count'
            ];
            fputcsv($handle, $headers, ',', '"', '\\');
            
            foreach ($quoteIds as $quoteId) {
                $quote = $this->quoteRepository->find($quoteId);
                if (!$quote) continue;

                // Archived quotes are invisible to bulk exports: silently
                // excluded (same visibility contract as the controllers —
                // an inaccessible ID never leaks data).
                if ($quote->isArchived()) continue;
                
                foreach ($quote->getBomLines() as $line) {
                    $row = [
                        $quote->getQuoteNumber() ?? $quote->getId(),
                        $line->getLineNumber(),
                        $line->getMpn(),
                        $line->getManufacturer(),
                        $line->getQuantity(),
                        $line->getProcurementSource(),
                        $line->getEffectiveUnitPrice(),
                        $line->getEffectiveExtendedPrice(),
                        $line->getConfidenceLevel(),
                        $line->getLifecycleWarning() ?? 'None',
                        $line->hasAlternatives() ? 'Yes' : 'No',
                        $line->getAlternativeCount(),
                    ];
                    fputcsv($handle, self::csvRow($row), ',', '"', '\\');
                }
            }

            fclose($handle);
        });
        
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="sourcing_report_' . date('Y-m-d_His') . '.csv"');
        
        return $response;
    }

    /**
     * Convert Lead entity to CSV row array
     * @param list<string> $fields
     * @return list<mixed>
     */
    private function leadToRow(Lead $lead, array $fields): array
    {
        $row = [];
        
        foreach ($fields as $field) {
            $value = match($field) {
                'id' => $lead->getId(),
                'company_name' => $lead->getCompanyName(),
                'legal_name' => $lead->getLegalName(),
                'website' => $lead->getWebsiteRoot(),
                'lead_url' => $lead->getLeadUrl(),
                'region' => $lead->getRegionTag(),
                'site_location' => $lead->getSiteLocation(),
                'us_state' => $lead->getUsState(),
                'us_city_metro' => $lead->getUsCityMetro(),
                'sectors' => implode(', ', $lead->getSectorTags() ?? []),
                'fit_signals' => $this->formatJsonField($lead->getFitSignals()),
                'morocco_signal' => $lead->getMoroccoSignal() ? 'Yes' : 'No',
                'quality_stack' => implode(', ', $lead->getQualityStack() ?? []),
                'emails' => implode(', ', $lead->getContactEmailsPublic() ?? []),
                'has_contact_form' => $lead->hasContactForm() ? 'Yes' : 'No',
                'contact_form_url' => $lead->getContactFormUrl(),
                'supplier_portal_url' => $lead->getSupplierPortalUrl(),
                'rfq_page_url' => $lead->getRfqRfpPageUrl(),
                'defense_flag' => $lead->getDefenseFlag() ? 'Yes' : 'No',
                'lead_score' => $lead->getLeadScore(),
                'review_status' => $lead->getReviewStatus(),
                'scraping_method' => $lead->getScrapingMethod(),
                'pages_scraped' => $lead->getPagesScraped(),
                'last_scraped_at' => $lead->getLastScrapedAt()?->format('Y-m-d H:i:s'),
                'notes' => $lead->getNotesAuto(),
                'created_at' => $lead->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => $lead->getUpdatedAt()?->format('Y-m-d H:i:s'),
                default => '',
            };
            
            $row[] = self::sanitizeCsvCell($value);
        }
        
        return $row;
    }

    /**
     * Sanitize a whole row and coerce non-scalar cells (which previously
     * crashed fputcsv under strict_types) to ''.
     *
     * @param list<mixed> $row
     * @return list<int|string|float|bool|null>
     */
    private static function csvRow(array $row): array
    {
        return array_map(
            static function (mixed $value): string|int|float|bool|null {
                $sanitized = self::sanitizeCsvCell($value);
                return is_scalar($sanitized) || $sanitized === null ? $sanitized : '';
            },
            $row
        );
    }

    /**
     * OWASP CSV formula injection guard: prefix string cells starting with
     * =, +, -, @ or a tab with a tab character. Numeric values are preserved.
     */
    public static function sanitizeCsvCell(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if ($value[0] === "\t") {
            return $value;
        }
        if (in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "\t" . $value;
        }
        return $value;
    }

    /**
     * Format JSON field for CSV
     * @param array<string|int, mixed>|null $data
     */
    private function formatJsonField(?array $data): string
    {
        if (!$data) return '';

        $parts = [];
        foreach ($data as $key => $value) {
            if ($value === true) {
                $parts[] = $key;
            } elseif (is_scalar($value)) {
                $parts[] = "$key: $value";
            }
            // non-scalar values (previously string-cast with warnings) are skipped
        }

        return implode(', ', $parts);
    }

    /**
     * Get human-readable line status
     */
    private function getLineStatus(BomLine $line): string
    {
        if ($line->isManuallyVerified()) {
            return 'Verified';
        }
        if ($line->requiresReview()) {
            return 'Needs Review';
        }
        if ($line->hasException()) {
            return 'Exception';
        }
        if ($line->getUnitPrice() === null && $line->getManualUnitPrice() === null) {
            return 'No Price';
        }
        return 'Auto-OK';
    }
}
