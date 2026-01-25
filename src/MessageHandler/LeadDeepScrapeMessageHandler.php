<?php

namespace App\MessageHandler;

use App\Entity\Lead;
use App\Message\LeadDeepScrapeMessage;
use App\Service\DeepScrapingService;
use App\Service\LlmEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for LeadDeepScrapeMessage
 * 
 * Processes lead websites in the background to extract:
 * - Email addresses
 * - Phone numbers
 * - Contact names
 * - Social media links
 * - Company description
 * 
 * Optionally uses LLM for additional enrichment.
 */
#[AsMessageHandler]
class LeadDeepScrapeMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeepScrapingService $scrapingService,
        private LoggerInterface $logger,
        private ?LlmEnrichmentService $llmService = null
    ) {}

    public function __invoke(LeadDeepScrapeMessage $message): void
    {
        $leadId = $message->getLeadId();
        $websiteUrl = $message->getWebsiteUrl();
        
        $this->logger->info('Starting deep scrape for lead', [
            'lead_id' => $leadId,
            'website' => $websiteUrl
        ]);
        
        // Find the lead
        $lead = $this->entityManager->getRepository(Lead::class)->find($leadId);
        
        if (!$lead) {
            $this->logger->warning('Lead not found for deep scrape', ['lead_id' => $leadId]);
            return;
        }
        
        try {
            // Scrape the website
            $scrapeResult = $this->scrapingService->scrapeWebsite(
                $websiteUrl,
                $message->getMaxPages()
            );
            
            // Update lead with scraped data
            $this->updateLeadFromScrapeResult($lead, $scrapeResult);
            
            // Optionally use LLM for enrichment
            if ($message->shouldUseLlm() && $this->llmService) {
                $this->enrichWithLlm($lead, $scrapeResult);
            }
            
            // Save changes
            $lead->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            $this->logger->info('Deep scrape completed for lead', [
                'lead_id' => $leadId,
                'emails_found' => count($scrapeResult['emails']),
                'phones_found' => count($scrapeResult['phones']),
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Deep scrape failed for lead', [
                'lead_id' => $leadId,
                'error' => $e->getMessage()
            ]);
            
            // Update lead to indicate scrape was attempted
            $notes = $lead->getNotesAuto() ?? '';
            $notes .= "\n[" . date('Y-m-d H:i') . "] Deep scrape failed: " . $e->getMessage();
            $lead->setNotesAuto($notes);
            $this->entityManager->flush();
        }
    }

    /**
     * Update lead entity with scraped data
     */
    private function updateLeadFromScrapeResult(Lead $lead, array $result): void
    {
        // Update emails if found
        if (!empty($result['emails'])) {
            $existingEmails = $lead->getContactEmailsPublic() ?? [];
            $allEmails = array_unique(array_merge($existingEmails, $result['emails']));
            $lead->setContactEmailsPublic(array_slice($allEmails, 0, 10));
        }
        
        // Update notes with phones and contact names
        $notes = $lead->getNotesAuto() ?? '';
        $newNotes = [];
        
        if (!empty($result['phones'])) {
            $newNotes[] = "Phones found: " . implode(', ', array_slice($result['phones'], 0, 3));
        }
        
        if (!empty($result['contact_names'])) {
            $newNotes[] = "Contacts found: " . implode(', ', array_slice($result['contact_names'], 0, 5));
        }
        
        if (!empty($result['social_links'])) {
            foreach ($result['social_links'] as $social) {
                if ($social['platform'] === 'linkedin' && !$lead->getLeadUrl()) {
                    // Could set LinkedIn company URL if we had that field on Lead
                    $newNotes[] = "LinkedIn: " . $social['url'];
                    break;
                }
            }
        }
        
        if (!empty($newNotes)) {
            $notes .= "\n[" . date('Y-m-d H:i') . "] Deep scrape results:\n" . implode("\n", $newNotes);
            $lead->setNotesAuto($notes);
        }
        
        // Update description if we found better about text
        if ($result['about_text'] && !$lead->getNotesAuto()) {
            // Don't overwrite existing notes, just add
            $notes .= "\n\nCompany Description: " . $result['about_text'];
            $lead->setNotesAuto($notes);
        }
        
        // Set last seen timestamp
        $lead->setLastSeen(new \DateTime());
        
        // Increase lead score if we found good contact info
        $currentScore = $lead->getLeadScore() ?? 0;
        $scoreBoost = 0;
        
        if (!empty($result['emails'])) {
            $scoreBoost += 10;
        }
        if (!empty($result['phones'])) {
            $scoreBoost += 5;
        }
        if (!empty($result['contact_names'])) {
            $scoreBoost += 5;
        }
        
        if ($scoreBoost > 0) {
            $lead->setLeadScore(min(100, $currentScore + $scoreBoost));
        }
    }

    /**
     * Enrich lead using LLM analysis
     */
    private function enrichWithLlm(Lead $lead, array $scrapeResult): void
    {
        if (!$this->llmService) {
            return;
        }
        
        try {
            // Use LLM to extract additional insights
            $llmResult = $this->llmService->enrichLeadFromScrapedContent(
                $lead->getCompanyName(),
                $scrapeResult['about_text'] ?? '',
                $scrapeResult['contact_names'] ?? []
            );
            
            if ($llmResult) {
                $notes = $lead->getNotesAuto() ?? '';
                $notes .= "\n[LLM Enrichment] " . ($llmResult['summary'] ?? '');
                
                if (!empty($llmResult['industry'])) {
                    $sectors = $lead->getSectorTags() ?? [];
                    $sectors[] = $llmResult['industry'];
                    $lead->setSectorTags(array_unique($sectors));
                }
                
                $lead->setNotesAuto($notes);
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('LLM enrichment failed', [
                'lead_id' => $lead->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}
