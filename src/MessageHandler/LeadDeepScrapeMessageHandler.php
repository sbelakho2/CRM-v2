<?php

namespace App\MessageHandler;

use App\Entity\Company;
use App\Entity\Contact;
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
 * - Structured contacts (name, email, phone, title, LinkedIn)
 * - Email addresses
 * - Phone numbers
 * - Social media links
 * - Company description
 * 
 * Creates Contact entities linked to the Lead's Company.
 * Optionally uses LLM for additional enrichment and contact discovery.
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
            // Refresh entity state to avoid race conditions with concurrent handlers
            $this->entityManager->refresh($lead);
            
            // Scrape the website
            $scrapeResult = $this->scrapingService->scrapeWebsite(
                $websiteUrl,
                $message->getMaxPages()
            );
            
            // Update lead with scraped data
            $this->updateLeadFromScrapeResult($lead, $scrapeResult);

            // Create Contact entities from structured contacts
            $contactsCreated = $this->createContactEntities($lead, $scrapeResult);
            
            // Optionally use LLM for enrichment
            if ($message->shouldUseLlm() && $this->llmService) {
                $contactsCreated += $this->enrichWithLlm($lead, $scrapeResult);
            }
            
            // Save changes
            $lead->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            
            $this->logger->info('Deep scrape completed for lead', [
                'lead_id' => $leadId,
                'emails_found' => count($scrapeResult['emails']),
                'phones_found' => count($scrapeResult['phones']),
                'structured_contacts' => count($scrapeResult['structured_contacts'] ?? []),
                'contacts_created' => $contactsCreated,
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
     * Create Contact entities from structured contacts discovered during scraping.
     *
     * Only creates contacts that meet quality thresholds:
     * - Must have both first and last name
     * - Must have at least one of: email, phone, LinkedIn
     * - Email domain must match company website domain (if available)
     * - Deduplicates against existing contacts for the company
     *
     * @return int Number of contacts created
     */
    private function createContactEntities(Lead $lead, array $scrapeResult): int
    {
        $structuredContacts = $scrapeResult['structured_contacts'] ?? [];
        if (empty($structuredContacts)) {
            return 0;
        }

        // Find or resolve the Company entity
        $company = $lead->getCompany();
        if (!$company) {
            // Try to find company by name
            $companyName = $lead->getCompanyName();
            if ($companyName) {
                $company = $this->entityManager->getRepository(Company::class)
                    ->createQueryBuilder('c')
                    ->where('LOWER(c.name) = :name')
                    ->setParameter('name', strtolower($companyName))
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($company) {
                    $lead->setCompany($company);
                }
            }
        }

        if (!$company) {
            // Create a Company from the Lead if we don't have one
            $companyName = $lead->getCompanyName();
            if (!$companyName) {
                $this->logger->debug('Cannot create contacts: no company linked to lead', ['lead_id' => $lead->getId()]);
                return 0;
            }

            $company = new Company();
            $company->setName($companyName);
            $company->setWebsite($lead->getWebsiteRoot() ?? $lead->getLeadUrl());
            $company->setPipelineStage('Prospect');
            $company->setAccountTier('C');
            $company->setSourceNotes('Auto-created from webcrawler deep scrape on ' . date('Y-m-d'));
            $company->setCreatedAt(new \DateTime());
            $company->setUpdatedAt(new \DateTime());
            if ($lead->getRegionTag()) {
                $company->setRegion($lead->getRegionTag());
            }
            if ($lead->getSiteLocation()) {
                $company->setPhysicalSite($lead->getSiteLocation());
            }
            $this->entityManager->persist($company);
            $lead->setCompany($company);
        }

        // Get existing contacts for dedup
        $existingContacts = $this->entityManager->getRepository(Contact::class)
            ->findBy(['company' => $company]);
        $existingKeys = [];
        foreach ($existingContacts as $existing) {
            $existingKeys[strtolower($existing->getFirstName() . '|' . $existing->getLastName())] = true;
            if ($existing->getEmail()) {
                $existingKeys[strtolower($existing->getEmail())] = true;
            }
        }

        // Extract company domain for email validation
        $companyDomain = null;
        $website = $company->getWebsite() ?? $lead->getWebsiteRoot() ?? $lead->getLeadUrl();
        if ($website) {
            $host = parse_url($website, PHP_URL_HOST);
            $companyDomain = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
        }

        $created = 0;

        foreach ($structuredContacts as $data) {
            $firstName = trim($data['first_name'] ?? '');
            $lastName = trim($data['last_name'] ?? '');

            if (!$firstName || !$lastName) {
                continue;
            }

            // Must have at least one reachable identifier
            $hasEmail = !empty($data['email']);
            $hasPhone = !empty($data['phone']);
            $hasLinkedIn = !empty($data['linkedin_url']);
            if (!$hasEmail && !$hasPhone && !$hasLinkedIn) {
                continue;
            }

            // Validate email domain matches company
            if ($hasEmail && $companyDomain) {
                $emailDomain = explode('@', $data['email'])[1] ?? '';
                $emailDomain = preg_replace('/^www\./', '', strtolower($emailDomain));
                // Only reject if email is from a clearly different company domain
                // Allow generic providers (gmail, outlook) as they might be legit for small companies
                $genericProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'protonmail.com'];
                if ($emailDomain !== $companyDomain && !in_array($emailDomain, $genericProviders, true)) {
                    $this->logger->debug('Skipping contact with mismatched email domain', [
                        'name' => "$firstName $lastName",
                        'email_domain' => $emailDomain,
                        'company_domain' => $companyDomain,
                    ]);
                    continue;
                }
            }

            // Dedup check
            $nameKey = strtolower("$firstName|$lastName");
            if (isset($existingKeys[$nameKey])) {
                continue;
            }
            if ($hasEmail && isset($existingKeys[strtolower($data['email'])])) {
                continue;
            }

            // Quality gate: score must be at least 30
            $quality = $this->scrapingService->scoreContactQuality($data, $companyDomain);
            if ($quality < 30) {
                $this->logger->debug('Skipping low-quality contact', [
                    'name' => "$firstName $lastName",
                    'quality' => $quality,
                ]);
                continue;
            }

            // Create the Contact entity
            $contact = new Contact();
            $contact->setCompany($company);
            $contact->setFirstName($firstName);
            $contact->setLastName($lastName);
            $contact->setSource('Webcrawler');
            $contact->setCreatedAt(new \DateTime());

            if ($hasEmail) {
                $contact->setEmail($data['email']);
            }
            if ($hasPhone) {
                $contact->setPhone($data['phone']);
            }
            if (!empty($data['job_title'])) {
                $contact->setJobTitle($data['job_title']);
            }
            if ($hasLinkedIn) {
                $contact->setLinkedInUrl($data['linkedin_url']);
            }

            // Mark as primary if this is a decision-maker
            if ($this->scrapingService->isDecisionMaker($data)) {
                $contact->setPrimaryContact(true);
                $contact->setNotes('Decision-maker (auto-detected). Quality score: ' . $quality);
            } else {
                $contact->setNotes('Quality score: ' . $quality);
            }

            $this->entityManager->persist($contact);
            $existingKeys[$nameKey] = true;
            if ($hasEmail) {
                $existingKeys[strtolower($data['email'])] = true;
            }
            $created++;

            $this->logger->info('Created contact from deep scrape', [
                'name' => "$firstName $lastName",
                'company' => $company->getName(),
                'title' => $data['job_title'] ?? 'unknown',
                'quality' => $quality,
                'decision_maker' => $this->scrapingService->isDecisionMaker($data),
            ]);
        }

        return $created;
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
        
        if (!empty($result['structured_contacts'])) {
            $contactSummary = [];
            foreach (array_slice($result['structured_contacts'], 0, 5) as $c) {
                $line = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if (!empty($c['job_title'])) {
                    $line .= ' (' . $c['job_title'] . ')';
                }
                if (!empty($c['email'])) {
                    $line .= ' <' . $c['email'] . '>';
                }
                $contactSummary[] = $line;
            }
            $newNotes[] = "Contacts found:\n  - " . implode("\n  - ", $contactSummary);
        } elseif (!empty($result['contact_names'])) {
            $newNotes[] = "Contact names: " . implode(', ', array_slice($result['contact_names'], 0, 5));
        }
        
        if (!empty($newNotes)) {
            $notes .= "\n[" . date('Y-m-d H:i') . "] Deep scrape results:\n" . implode("\n", $newNotes);
        }
        
        // Append company description from about text if available
        if (!empty($result['about_text'])) {
            $notes .= "\n\nCompany Description: " . $result['about_text'];
        }
        
        // Persist accumulated notes
        if ($notes !== ($lead->getNotesAuto() ?? '')) {
            $lead->setNotesAuto($notes);
        }
        
        // Set scraping metadata
        $lead->setLastScrapedAt(new \DateTime());
        $lead->setPagesScraped($result['pages_scraped'] ?? null);
        $lead->setScrapingMethod($result['scraping_method'] ?? 'static');
        $lead->setHasContactForm(!empty($result['has_contact_form']));
        
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
        if (!empty($result['structured_contacts'])) {
            // Boost more for structured contacts, especially decision-makers
            $decisionMakers = array_filter($result['structured_contacts'], fn($c) => $this->scrapingService->isDecisionMaker($c));
            $scoreBoost += count($decisionMakers) > 0 ? 15 : 5;
        } elseif (!empty($result['contact_names'])) {
            $scoreBoost += 5;
        }
        
        if ($scoreBoost > 0) {
            $lead->setLeadScore(min(100, $currentScore + $scoreBoost));
        }
    }

    /**
     * Enrich lead using LLM analysis.
     * Now also uses LLM to extract contacts from scraped text
     * and creates Contact entities from the results.
     *
     * @return int Number of additional contacts created from LLM
     */
    private function enrichWithLlm(Lead $lead, array $scrapeResult): int
    {
        if (!$this->llmService || !$this->llmService->isConfigured()) {
            return 0;
        }
        
        $contactsCreated = 0;
        
        try {
            // 1) Standard enrichment (summary, industry, key_person)
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
                
                // Use key_person data from LLM (was previously discarded!)
                if (!empty($llmResult['key_person']['name'])) {
                    $personName = $llmResult['key_person']['name'];
                    $personRole = $llmResult['key_person']['role'] ?? null;
                    
                    $notes .= "\nKey decision-maker: " . $personName;
                    if ($personRole) {
                        $notes .= " ({$personRole})";
                    }
                    
                    // Try to create a Contact entity from the key_person
                    $company = $lead->getCompany();
                    if ($company) {
                        $parts = preg_split('/\s+/', trim($personName));
                        if (count($parts) >= 2) {
                            $firstName = array_shift($parts);
                            $lastName = implode(' ', $parts);
                            
                            // Check for duplicates
                            $exists = $this->entityManager->getRepository(Contact::class)
                                ->createQueryBuilder('c')
                                ->where('c.company = :company')
                                ->andWhere('LOWER(c.firstName) = :first')
                                ->andWhere('LOWER(c.lastName) = :last')
                                ->setParameter('company', $company)
                                ->setParameter('first', strtolower($firstName))
                                ->setParameter('last', strtolower($lastName))
                                ->setMaxResults(1)
                                ->getQuery()
                                ->getOneOrNullResult();
                            
                            if (!$exists) {
                                $contact = new Contact();
                                $contact->setCompany($company);
                                $contact->setFirstName($firstName);
                                $contact->setLastName($lastName);
                                if ($personRole) {
                                    $contact->setJobTitle($personRole);
                                }
                                $contact->setSource('Webcrawler (LLM)');
                                $contact->setPrimaryContact(true);
                                $contact->setNotes('Identified as key decision-maker by AI analysis');
                                $contact->setCreatedAt(new \DateTime());

                                // Try email pattern matching
                                if (!empty($llmResult['primary_email_pattern'])) {
                                    $emailPattern = $llmResult['primary_email_pattern'];
                                    // Replace placeholders with actual name
                                    $guessedEmail = str_replace(
                                        ['firstname', 'lastname', 'first', 'last'],
                                        [strtolower($firstName), strtolower($lastName), strtolower($firstName), strtolower($lastName)],
                                        strtolower($emailPattern)
                                    );
                                    if (filter_var($guessedEmail, FILTER_VALIDATE_EMAIL)) {
                                        $contact->setEmail($guessedEmail);
                                        $contact->setNotes($contact->getNotes() . "\nEmail generated from pattern: {$emailPattern}");
                                    }
                                }

                                $this->entityManager->persist($contact);
                                $contactsCreated++;
                                
                                $this->logger->info('Created contact from LLM key_person', [
                                    'name' => "$firstName $lastName",
                                    'role' => $personRole,
                                    'company' => $company->getName(),
                                ]);
                            }
                        }
                    }
                }
                
                $lead->setNotesAuto($notes);
            }

            // 2) Use the previously-dead extractContactsFromText() for raw text analysis
            $rawText = ($scrapeResult['about_text'] ?? '');
            foreach ($scrapeResult['structured_contacts'] ?? [] as $c) {
                $rawText .= "\n" . trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                if (!empty($c['job_title'])) {
                    $rawText .= ' - ' . $c['job_title'];
                }
            }

            if (strlen($rawText) > 50) {
                $llmContacts = $this->llmService->extractContactsFromText($rawText);
                if (!empty($llmContacts['names']) && is_array($llmContacts['names'])) {
                    $company = $lead->getCompany();
                    if ($company) {
                        foreach ($llmContacts['names'] as $personData) {
                            if (!is_array($personData) || empty($personData['name'])) {
                                continue;
                            }
                            $parts = preg_split('/\s+/', trim($personData['name']));
                            if (count($parts) < 2) {
                                continue;
                            }
                            $firstName = array_shift($parts);
                            $lastName = implode(' ', $parts);
                            $role = $personData['role'] ?? null;

                            // Dedup
                            $exists = $this->entityManager->getRepository(Contact::class)
                                ->createQueryBuilder('c')
                                ->where('c.company = :company')
                                ->andWhere('LOWER(c.firstName) = :first')
                                ->andWhere('LOWER(c.lastName) = :last')
                                ->setParameter('company', $company)
                                ->setParameter('first', strtolower($firstName))
                                ->setParameter('last', strtolower($lastName))
                                ->setMaxResults(1)
                                ->getQuery()
                                ->getOneOrNullResult();

                            if (!$exists) {
                                $contact = new Contact();
                                $contact->setCompany($company);
                                $contact->setFirstName($firstName);
                                $contact->setLastName($lastName);
                                if ($role) {
                                    $contact->setJobTitle($role);
                                }
                                $contact->setSource('Webcrawler (LLM text)');
                                $contact->setNotes('Extracted from page content by AI');
                                $contact->setCreatedAt(new \DateTime());
                                $this->entityManager->persist($contact);
                                $contactsCreated++;
                            }
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('LLM enrichment failed', [
                'lead_id' => $lead->getId(),
                'error' => $e->getMessage()
            ]);
        }

        return $contactsCreated;
    }
}
