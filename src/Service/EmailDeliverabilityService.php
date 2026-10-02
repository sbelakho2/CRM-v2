<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailSend;
use App\Entity\EmailCampaign;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for email deliverability monitoring and bounce handling
 * 
 * Features:
 * - SPF/DKIM/DMARC validation
 * - Bounce detection and classification (hard/soft)
 * - ISP feedback loop processing
 * - Suppression list management
 * - Reputation monitoring
 */
class EmailDeliverabilityService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Handle a bounce notification
     * 
     * @param string $bounceType 'hard' or 'soft'
     */
    public function processBounce(
        EmailSend $emailSend,
        string $bounceType,
        ?string $reason = null
    ): void {
        $emailSend->setBounced(true);
        $emailSend->setStatus('failed');
        $emailSend->setFailureReason($reason ?? "Bounced ($bounceType)");

        // For hard bounces, add email to suppression list
        if ($bounceType === 'hard') {
            $this->addToSuppressionList($emailSend->getEmailAddress(), 'hard_bounce', $reason);
        }

        // For soft bounces, increment retry count
        if ($bounceType === 'soft') {
            $retryCount = $emailSend->getRetryCount();
            $emailSend->setRetryCount($retryCount + 1);

            // After 3 soft bounces, treat as hard bounce
            if ($retryCount >= 2) {
                $this->addToSuppressionList($emailSend->getEmailAddress(), 'soft_bounce_limit', $reason);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Process a complaint (spam report)
     */
    public function processComplaint(EmailSend $emailSend, ?string $reason = null): void
    {
        // Mark email as failed
        $emailSend->setStatus('failed');
        $emailSend->setFailureReason('Spam complaint: ' . ($reason ?? 'User reported as spam'));

        // Add to suppression list immediately
        $this->addToSuppressionList($emailSend->getEmailAddress(), 'spam_complaint', $reason);

        // Unsubscribe the contact via suppression list (handled by addToSuppressionList above)

        $this->entityManager->flush();
    }

    /**
     * Add an email address to the global suppression list
     */
    private function addToSuppressionList(
        ?string $email,
        string $reason,
        ?string $details = null
    ): void {
        if (!$email) {
            return;
        }

        // Check if already in suppression list
        $existing = $this->entityManager->getRepository(\App\Entity\EmailUnsubscribe::class)
            ->findOneBy(['email' => $email]);

        if ($existing) {
            // Already suppressed
            return;
        }

        // Create unsubscribe record
        $unsubscribe = new \App\Entity\EmailUnsubscribe();
        $unsubscribe->setEmail($email);
        $unsubscribe->setReason($reason);
        $unsubscribe->setFeedbackText($details);
        $unsubscribe->setUnsubscribedAt(new \DateTime());

        $this->entityManager->persist($unsubscribe);
        $this->entityManager->flush();
    }

    /**
     * Check if an email is on the suppression list
     */
    public function isSuppressed(string $email): bool
    {
        $unsubscribe = $this->entityManager->getRepository(\App\Entity\EmailUnsubscribe::class)
            ->findOneBy(['email' => $email]);

        return $unsubscribe !== null;
    }

    /**
     * Remove an email from the suppression list
     */
    public function removeFromSuppressionList(string $email): bool
    {
        $unsubscribe = $this->entityManager->getRepository(\App\Entity\EmailUnsubscribe::class)
            ->findOneBy(['email' => $email]);

        if ($unsubscribe) {
            $this->entityManager->remove($unsubscribe);
            $this->entityManager->flush();
            return true;
        }

        return false;
    }

    /**
     * Get all suppressed emails
     *
     * @return list<\App\Entity\EmailUnsubscribe>
     */
    public function getSuppressionList(int $limit = 100, int $offset = 0): array
    {
        /** @var list<\App\Entity\EmailUnsubscribe> $list */
        $list = $this->entityManager->getRepository(\App\Entity\EmailUnsubscribe::class)
            ->findBy([], ['unsubscribedAt' => 'DESC'], $limit, $offset);

        return $list;
    }

    /**
     * Validate SPF record for a domain
     *
     * @return array{valid: bool, error?: string, record?: string, mechanisms?: list<array{qualifier: string, mechanism: string, value: string|null}>}
     */
    public function validateSpf(string $domain): array
    {
        $txtRecords = @dns_get_record($domain, DNS_TXT);

        if (!$txtRecords) {
            return [
                'valid' => false,
                'error' => 'No TXT records found for domain',
            ];
        }

        $spfRecord = null;
        foreach ($txtRecords as $record) {
            if (isset($record['txt']) && is_string($record['txt']) && str_starts_with($record['txt'], 'v=spf1')) {
                $spfRecord = $record['txt'];
                break;
            }
        }

        if (!$spfRecord) {
            return [
                'valid' => false,
                'error' => 'No SPF record found',
            ];
        }

        return [
            'valid' => true,
            'record' => $spfRecord,
            'mechanisms' => $this->parseSpfMechanisms($spfRecord),
        ];
    }

    /**
     * Parse SPF mechanisms from record
     *
     * @return list<array{qualifier: string, mechanism: string, value: string|null}>
     */
    private function parseSpfMechanisms(string $spfRecord): array
    {
        $parts = explode(' ', $spfRecord);
        $mechanisms = [];

        foreach ($parts as $part) {
            if (preg_match('/^([+\-~?])?(all|a|mx|ptr|ip4|ip6|include|exists):?(.*)$/', $part, $matches)) {
                $mechanisms[] = [
                    'qualifier' => $matches[1] !== '' ? $matches[1] : '+',
                    'mechanism' => $matches[2],
                    'value' => $matches[3] !== '' ? $matches[3] : null,
                ];
            }
        }

        return $mechanisms;
    }

    /**
     * Validate DKIM selector for a domain
     */
    /**
     * Validate DKIM selector for a domain
     *
     * @return array{valid: bool, error?: string, record?: string, selector?: string}
     */
    public function validateDkim(string $domain, string $selector = 'default'): array
    {
        $dkimDomain = $selector . '._domainkey.' . $domain;
        $txtRecords = @dns_get_record($dkimDomain, DNS_TXT);

        if (!$txtRecords) {
            return [
                'valid' => false,
                'error' => 'No DKIM record found for selector: ' . $selector,
            ];
        }

        $firstRecord = $txtRecords[0];
        $dkimRecord = isset($firstRecord['txt']) && is_string($firstRecord['txt']) ? $firstRecord['txt'] : null;

        if (!$dkimRecord || !str_contains($dkimRecord, 'v=DKIM1')) {
            return [
                'valid' => false,
                'error' => 'Invalid DKIM record format',
            ];
        }

        return [
            'valid' => true,
            'record' => $dkimRecord,
            'selector' => $selector,
        ];
    }

    /**
     * Validate DMARC policy for a domain
     */
    /**
     * Validate DMARC policy for a domain
     *
     * @return array{valid: bool, error?: string, record?: string, policy?: array{policy: string, subdomainPolicy: string|null, percentage: int, rua: string|null, ruf: string|null}}
     */
    public function validateDmarc(string $domain): array
    {
        $dmarcDomain = '_dmarc.' . $domain;
        $txtRecords = @dns_get_record($dmarcDomain, DNS_TXT);

        if (!$txtRecords) {
            return [
                'valid' => false,
                'error' => 'No DMARC record found',
            ];
        }

        $firstRecord = $txtRecords[0];
        $dmarcRecord = isset($firstRecord['txt']) && is_string($firstRecord['txt']) ? $firstRecord['txt'] : null;

        if (!$dmarcRecord || !str_starts_with($dmarcRecord, 'v=DMARC1')) {
            return [
                'valid' => false,
                'error' => 'Invalid DMARC record format',
            ];
        }

        return [
            'valid' => true,
            'record' => $dmarcRecord,
            'policy' => $this->parseDmarcPolicy($dmarcRecord),
        ];
    }

    /**
     * Parse DMARC policy from record
     *
     * @return array{policy: string, subdomainPolicy: string|null, percentage: int, rua: string|null, ruf: string|null}
     */
    private function parseDmarcPolicy(string $dmarcRecord): array
    {
        $tags = [];
        $parts = explode(';', str_replace('v=DMARC1;', '', $dmarcRecord));

        foreach ($parts as $part) {
            $part = trim($part);
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $tags[trim($key)] = trim($value);
            }
        }

        return [
            'policy' => $tags['p'] ?? 'none',
            'subdomainPolicy' => $tags['sp'] ?? null,
            'percentage' => (int)($tags['pct'] ?? 100),
            'rua' => $tags['rua'] ?? null, // Aggregate reports
            'ruf' => $tags['ruf'] ?? null, // Forensic reports
        ];
    }

    /**
     * Get deliverability score for a campaign
     */
    /**
     * Get deliverability score for a campaign
     *
     * @return array{score: float, rating: string, deliveryRate: float, bounceRate: float, stats: array{total: int, delivered: int, bounced: int, failed: int}}
     */
    public function getCampaignDeliverabilityScore(EmailCampaign $campaign): array
    {
        /** @var array{total: mixed, delivered: mixed, bounced: mixed, failed: mixed} $stats */
        $stats = $this->entityManager->createQuery(
            'SELECT 
                COUNT(es.id) as total,
                SUM(CASE WHEN es.status = :sent THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN es.bounced = true THEN 1 ELSE 0 END) as bounced,
                SUM(CASE WHEN es.status = :failed THEN 1 ELSE 0 END) as failed
             FROM App\Entity\EmailSend es 
             WHERE es.campaign = :campaign'
        )
        ->setParameter('campaign', $campaign)
        ->setParameter('sent', 'sent')
        ->setParameter('failed', 'failed')
        ->getSingleResult();

        $total = is_numeric($stats['total']) ? (int) $stats['total'] : 0;
        $delivered = is_numeric($stats['delivered']) ? (int) $stats['delivered'] : 0;
        $bounced = is_numeric($stats['bounced']) ? (int) $stats['bounced'] : 0;
        $failed = is_numeric($stats['failed']) ? (int) $stats['failed'] : 0;

        $deliveryRate = $total > 0 ? ($delivered / $total) * 100 : 0;
        $bounceRate = $total > 0 ? ($bounced / $total) * 100 : 0;

        // Calculate deliverability score (0-100)
        $score = 100;
        $score -= ($bounceRate * 2); // Penalize bounces heavily
        $score -= ((100 - $deliveryRate) * 1.5); // Penalize failed deliveries

        $score = max(0, min(100, $score));

        // Determine rating
        $rating = match (true) {
            $score >= 95 => 'Excellent',
            $score >= 85 => 'Good',
            $score >= 70 => 'Fair',
            $score >= 50 => 'Poor',
            default => 'Critical',
        };

        return [
            'score' => round($score, 2),
            'rating' => $rating,
            'deliveryRate' => round($deliveryRate, 2),
            'bounceRate' => round($bounceRate, 2),
            'stats' => [
                'total' => $total,
                'delivered' => $delivered,
                'bounced' => $bounced,
                'failed' => $failed,
            ],
        ];
    }

    /**
     * Get bounce statistics grouped by type
     */
    /**
     * Get bounce statistics grouped by type
     *
     * @return array{hardBounces: int, softBounces: int, spamComplaints: int, total: int}
     */
    public function getBounceStatistics(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        // Get hard bounces
        $hardBounces = $this->entityManager->createQuery(
            'SELECT COUNT(eu.id) as count 
             FROM App\Entity\EmailUnsubscribe eu 
             WHERE eu.reason = :hardBounce 
             AND eu.unsubscribedAt BETWEEN :start AND :end'
        )
        ->setParameter('hardBounce', 'hard_bounce')
        ->setParameter('start', $startDate)
        ->setParameter('end', $endDate)
        ->getSingleScalarResult();
        $hardBounces = is_numeric($hardBounces) ? (int) $hardBounces : 0;

        // Get soft bounces
        $softBounces = $this->entityManager->createQuery(
            'SELECT COUNT(eu.id) as count 
             FROM App\Entity\EmailUnsubscribe eu 
             WHERE eu.reason = :softBounce 
             AND eu.unsubscribedAt BETWEEN :start AND :end'
        )
        ->setParameter('softBounce', 'soft_bounce_limit')
        ->setParameter('start', $startDate)
        ->setParameter('end', $endDate)
        ->getSingleScalarResult();
        $softBounces = is_numeric($softBounces) ? (int) $softBounces : 0;

        // Get spam complaints
        $spamComplaints = $this->entityManager->createQuery(
            'SELECT COUNT(eu.id) as count 
             FROM App\Entity\EmailUnsubscribe eu 
             WHERE eu.reason = :spam 
             AND eu.unsubscribedAt BETWEEN :start AND :end'
        )
        ->setParameter('spam', 'spam_complaint')
        ->setParameter('start', $startDate)
        ->setParameter('end', $endDate)
        ->getSingleScalarResult();
        $spamComplaints = is_numeric($spamComplaints) ? (int) $spamComplaints : 0;

        return [
            'hardBounces' => $hardBounces,
            'softBounces' => $softBounces,
            'spamComplaints' => $spamComplaints,
            'total' => $hardBounces + $softBounces + $spamComplaints,
        ];
    }

    /**
     * Clean suppression list (remove old soft bounces)
     */
    public function cleanSuppressionList(int $daysOld = 90): int
    {
        $cutoffDate = new \DateTime("-{$daysOld} days");

        $deleted = $this->entityManager->createQuery(
            'DELETE FROM App\Entity\EmailUnsubscribe eu 
             WHERE eu.reason = :softBounce 
             AND eu.unsubscribedAt < :cutoff'
        )
        ->setParameter('softBounce', 'soft_bounce_limit')
        ->setParameter('cutoff', $cutoffDate)
        ->execute();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }
}
