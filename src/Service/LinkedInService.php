<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Activity;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;

class LinkedInService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository
    ) {}

    /**
     * Extract LinkedIn profile information
     * In production, this would use LinkedIn API or web scraping
     */
    public function extractProfileInfo(string $linkedInUrl): array
    {
        // Mock implementation - would use LinkedIn API in production
        return [
            'name' => null,
            'title' => null,
            'company' => null,
            'location' => null,
            'email' => null,
            'phone' => null,
        ];
    }

    /**
     * Validate LinkedIn URL format
     */
    public function isValidLinkedInUrl(string $url): bool
    {
        return preg_match('/^https?:\/\/(www\.)?linkedin\.com\/(in|company)\/[\w-]+\/?$/i', $url) === 1;
    }

    /**
     * Get LinkedIn profile URL from contact
     */
    public function getProfileUrl(Contact $contact): ?string
    {
        return $contact->getLinkedInUrl();
    }

    /**
     * Get LinkedIn company page URL
     */
    public function getCompanyUrl(Company $company): ?string
    {
        return $company->getLinkedInUrl();
    }

    /**
     * Track LinkedIn outreach activity
     */
    public function trackLinkedInOutreach(Contact $contact, string $messageType, string $notes, $user = null): Activity
    {
        $activity = new Activity();
        $activity->setCompany($contact->getCompany());
        $activity->setType('LinkedIn ' . $messageType); // e.g., "LinkedIn InMail", "LinkedIn Connection"
        $activity->setDescription($notes);
        $activity->setActivityDate(new \DateTime());
        
        if ($user) {
            $activity->setUser($user);
        }

        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        return $activity;
    }

    /**
     * Find contacts by LinkedIn URL pattern
     */
    public function findContactsByCompanyLinkedIn(string $companyLinkedInUrl): array
    {
        // Extract company identifier from URL
        if (!$this->isValidLinkedInUrl($companyLinkedInUrl)) {
            return [];
        }

        // Would implement search logic here
        return [];
    }

    /**
     * Get LinkedIn engagement metrics for contact
     */
    public function getEngagementMetrics(Contact $contact): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        $activities = $qb->select('a')
            ->from(Activity::class, 'a')
            ->where('a.company = :company')
            ->andWhere('a.type LIKE :linkedIn')
            ->setParameter('company', $contact->getCompany())
            ->setParameter('linkedIn', 'LinkedIn%')
            ->orderBy('a.activityDate', 'DESC')
            ->getQuery()
            ->getResult();

        return [
            'total_interactions' => count($activities),
            'last_interaction' => count($activities) > 0 ? $activities[0]->getActivityDate() : null,
            'connection_requests' => $this->countByType($activities, 'LinkedIn Connection'),
            'inmails_sent' => $this->countByType($activities, 'LinkedIn InMail'),
            'profile_views' => $this->countByType($activities, 'LinkedIn Profile View'),
        ];
    }

    /**
     * Helper to count activities by type
     */
    private function countByType(array $activities, string $type): int
    {
        return count(array_filter($activities, fn($a) => $a->getType() === $type));
    }

    /**
     * Suggest LinkedIn outreach message templates
     */
    public function getMessageTemplates(string $sector, string $language = 'EN'): array
    {
        $templates = [
            'EN' => [
                'connection_request' => "Hi {firstName}, I noticed your role at {company} in the {sector} sector. I'd like to connect and explore potential collaboration opportunities with STARZ Morocco.",
                'inmail_intro' => "Dear {firstName},\n\nI hope this message finds you well. I'm reaching out from STARZ Morocco regarding our specialized capabilities in {sector} manufacturing...",
                'follow_up' => "Hi {firstName},\n\nI wanted to follow up on my previous message regarding potential collaboration opportunities...",
            ],
            'FR' => [
                'connection_request' => "Bonjour {firstName}, j'ai remarqué votre rôle chez {company} dans le secteur {sector}. J'aimerais me connecter pour explorer des opportunités de collaboration avec STARZ Maroc.",
                'inmail_intro' => "Cher(e) {firstName},\n\nJ'espère que ce message vous trouve bien. Je vous contacte depuis STARZ Maroc concernant nos capacités spécialisées en fabrication {sector}...",
                'follow_up' => "Bonjour {firstName},\n\nJe voulais faire un suivi de mon message précédent concernant des opportunités de collaboration potentielles...",
            ],
        ];

        return $templates[$language] ?? $templates['EN'];
    }
}
