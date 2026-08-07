<?php

namespace App\Controller;

use App\Service\CsvExportService;
use App\Repository\QuoteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export Controller
 * 
 * Handles CSV exports for:
 * - Lead lists (enriched discovery data)
 * - Quote BOM data (pricing, confidence, alternatives)
 * - Sourcing reports (multi-quote comparisons)
 */
#[Route('/export')]
#[IsGranted('ROLE_USER')]
class ExportController extends AbstractController
{
    public function __construct(
        private CsvExportService $exportService,
        private QuoteRepository $quoteRepository
    ) {}

    /**
     * Export leads to CSV
     * 
     * Query params:
     * - status: Filter by review status
     * - hasEmails: Include only leads with emails (1/0)
     * - hasContactForm: Include only leads with contact forms (1/0)
     * - sector: Filter by sector tag
     * - region: Filter by region
     * - dateFrom: Filter from date (Y-m-d)
     * - dateTo: Filter to date (Y-m-d)
     * - fields: Comma-separated list of fields to include
     */
    #[Route('/leads', name: 'export_leads', methods: ['GET'])]
    public function exportLeads(Request $request): StreamedResponse
    {
        $filters = [];
        
        if ($status = $request->query->get('status')) {
            $filters['status'] = $status;
        }
        
        if ($request->query->getBoolean('hasEmails')) {
            $filters['hasEmails'] = true;
        }
        
        if ($request->query->getBoolean('hasContactForm')) {
            $filters['hasContactForm'] = true;
        }
        
        if ($sector = $request->query->get('sector')) {
            $filters['sector'] = $sector;
        }
        
        if ($region = $request->query->get('region')) {
            $filters['region'] = $region;
        }
        
        if ($dateFrom = $request->query->get('dateFrom')) {
            $filters['dateFrom'] = $dateFrom;
        }
        
        if ($dateTo = $request->query->get('dateTo')) {
            $filters['dateTo'] = $dateTo;
        }
        
        // Custom field selection
        $fields = null;
        if ($fieldsParam = $request->query->get('fields')) {
            $fields = array_map('trim', explode(',', $fieldsParam));
        }
        
        return $this->exportService->exportLeads($filters, $fields);
    }

    /**
     * Export a single quote's BOM to CSV
     */
    #[Route('/quote/{id}/bom', name: 'export_quote_bom', methods: ['GET'])]
    public function exportQuoteBom(int $id, Request $request): Response
    {
        $quote = $this->quoteRepository->find($id);
        
        if (!$quote) {
            throw $this->createNotFoundException('Quote not found');
        }
        
        $includeAlternatives = $request->query->getBoolean('alternatives', true);
        $includeConfidence = $request->query->getBoolean('confidence', true);
        
        return $this->exportService->exportQuoteBom($quote, $includeAlternatives, $includeConfidence);
    }

    /**
     * Export sourcing report for multiple quotes
     * 
     * Query params:
     * - quotes: Comma-separated list of quote IDs
     */
    #[Route('/sourcing-report', name: 'export_sourcing_report', methods: ['GET'])]
    public function exportSourcingReport(Request $request): Response
    {
        $quotesParam = $request->query->get('quotes', '');
        
        if (empty($quotesParam)) {
            throw new BadRequestHttpException('No quotes specified');
        }
        
        $quoteIds = array_map('intval', explode(',', $quotesParam));
        
        return $this->exportService->exportSourcingReport($quoteIds);
    }
}
