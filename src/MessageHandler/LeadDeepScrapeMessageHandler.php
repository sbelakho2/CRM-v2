<?php

namespace App\MessageHandler;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Message\LeadDeepScrapeMessage;
use App\Service\DeepScrapingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class LeadDeepScrapeMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeepScrapingService $scrapingService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(LeadDeepScrapeMessage $message): void
    {
        $leadId = $message->getLeadId();
        $websiteUrl = $message->getWebsiteUrl();

        $this->logger->info('Starting deep scrape for lead', [
            'lead_id' => $leadId,
            'website' => $websiteUrl
        ]);

        $lead = $this->entityManager->getRepository(Lead::class)->find($leadId);

        if (!$lead) {
            $this->logger->warning('Lead not found for deep scrape', ['lead_id' => $leadId]);
            return;
        }

        try {
            try {
                $this->entityManager->refresh($lead);
            } catch (\Exception $e) {
                $this->logger->warning('Could not refresh lead entity', [
                    'lead_id' => $leadId,
                    'error' => $e->getMessage(),
                ]);
            }

            $scrapeResult = $this->scrapingService->scrapeWebsite(
                $websiteUrl,
                $message->getMaxPages()
            );

            $this->updateLeadFromScrapeResult($lead, $scrapeResult);

            $contactsCreated = $this->createContactEntities($lead, $scrapeResult);

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

            $notes = $lead->getNotesAuto() ?? '';
            $notes .= "\n[" . date('Y-m-d H:i') . "] Deep scrape failed: " . $e->getMessage();
            $lead->setNotesAuto($notes);

            if ($this->entityManager->isOpen()) {
                $this->entityManager->flush();
            }
        }
    }

    private function createContactEntities(Lead $lead, array $scrapeResult): int
    {
        $structuredContacts = $scrapeResult['structured_contacts'] ?? [];
        if (empty($structuredContacts)) {
            return 0;
        }

        $company = $lead->getCompany();
        if (!$company) {
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

        $existingContacts = $this->entityManager->getRepository(Contact::class)
            ->findBy(['company' => $company]);
        $existingKeys = [];
        foreach ($existingContacts as $existing) {
            $existingKeys[strtolower($existing->getFirstName() . '|' . $existing->getLastName())] = true;
            if ($existing->getEmail()) {
                $existingKeys[strtolower($existing->getEmail())] = true;
            }
        }

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

            $hasEmail = !empty($data['email']);
            $hasPhone = !empty($data['phone']);
            $hasLinkedIn = !empty($data['linkedin_url']);
            if (!$hasEmail && !$hasPhone && !$hasLinkedIn) {
                continue;
            }

            if ($hasEmail && $companyDomain) {
                $emailDomain = explode('@', $data['email'])[1] ?? '';
                $emailDomain = preg_replace('/^www\./', '', strtolower($emailDomain));
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

            $nameKey = strtolower("$firstName|$lastName");
            if (isset($existingKeys[$nameKey])) {
                continue;
            }
            if ($hasEmail && isset($existingKeys[strtolower($data['email'])])) {
                continue;
            }

            $quality = $this->scrapingService->scoreContactQuality($data, $companyDomain);
            if ($quality < 30) {
                $this->logger->debug('Skipping low-quality contact', [
                    'name' => "$firstName $lastName",
                    'quality' => $quality,
                ]);
                continue;
            }

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

    private function updateLeadFromScrapeResult(Lead $lead, array $result): void
    {
        if (!empty($result['emails'])) {
            $existingEmails = $lead->getContactEmailsPublic() ?? [];
            $allEmails = array_unique(array_merge($existingEmails, $result['emails']));
            $lead->setContactEmailsPublic(array_slice($allEmails, 0, 10));
        }

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

        if (!empty($result['about_text'])) {
            $notes .= "\n\nCompany Description: " . $result['about_text'];
        }

        if ($notes !== ($lead->getNotesAuto() ?? '')) {
            $lead->setNotesAuto($notes);
        }

        $lead->setLastScrapedAt(new \DateTime());
        $lead->setPagesScraped($result['pages_scraped'] ?? null);
        $lead->setScrapingMethod($result['scraping_method'] ?? 'static');
        $lead->setHasContactForm(!empty($result['has_contact_form']));

        $lead->setLastSeen(new \DateTime());

        $currentScore = $lead->getLeadScore() ?? 0;
        $scoreBoost = 0;

        if (!empty($result['emails'])) {
            $scoreBoost += 10;
        }
        if (!empty($result['phones'])) {
            $scoreBoost += 5;
        }
        if (!empty($result['structured_contacts'])) {
            $decisionMakers = array_filter($result['structured_contacts'], fn($c) => $this->scrapingService->isDecisionMaker($c));
            $scoreBoost += count($decisionMakers) > 0 ? 15 : 5;
        } elseif (!empty($result['contact_names'])) {
            $scoreBoost += 5;
        }

        if ($scoreBoost > 0) {
            $lead->setLeadScore(min(100, $currentScore + $scoreBoost));
        }
    }

}
