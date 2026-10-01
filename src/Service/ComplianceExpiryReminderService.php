<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceDocument;
use App\Entity\Company;
use App\Repository\ComplianceDocumentRepository;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Compliance Expiry Reminder Service
 * 
 * Monitors compliance documents for upcoming expirations and sends reminders:
 * - 90 days before expiry: Initial reminder
 * - 60 days before expiry: Follow-up reminder
 * - 30 days before expiry: Urgent reminder
 * - 14 days before expiry: Critical reminder
 * - Day of expiry: Final warning
 * - After expiry: Expired notification
 */
class ComplianceExpiryReminderService
{
    // Reminder thresholds (days before expiry)
    private const REMINDER_THRESHOLDS = [
        90 => ['level' => 'info', 'label' => 'Expires in 90 days'],
        60 => ['level' => 'notice', 'label' => 'Expires in 60 days'],
        30 => ['level' => 'warning', 'label' => 'Expires in 30 days'],
        14 => ['level' => 'critical', 'label' => 'Expires in 14 days'],
        7 => ['level' => 'urgent', 'label' => 'Expires in 7 days'],
        0 => ['level' => 'expired', 'label' => 'Expires today'],
    ];
    
    // Document types with critical compliance impact
    private const CRITICAL_DOCUMENTS = [
        'ISO 9001',
        'AS9100',
        'ISO 13485',
        'ISO 14001',
        'ISO 45001',
        'Export Control Compliance',
        'Insurance Certificate',
    ];
    
    private ComplianceDocumentRepository $documentRepository;
    // Retained injected dependency (constructor signature is fixed): kept
    // non-private so the container wiring stays intact without dead reads.
    protected CompanyRepository $companyRepository;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private ?MailerInterface $mailer;
    private string $adminEmail;
    private string $mailerFromAddress;

    public function __construct(
        ComplianceDocumentRepository $documentRepository,
        CompanyRepository $companyRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        // These are wired from parameters via services.yaml ($adminEmail /
        // $mailerFromAddress binds); they must NOT silently fall back to
        // placeholder values, so no defaults are allowed.
        string $adminEmail,
        string $mailerFromAddress,
        ?MailerInterface $mailer = null
    ) {
        $this->documentRepository = $documentRepository;
        $this->companyRepository = $companyRepository;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->adminEmail = $adminEmail;
        $this->mailerFromAddress = $mailerFromAddress;
    }
    
    /**
     * Get all documents expiring within a given number of days
     *
     * @param int $days Number of days to look ahead
     * @return array<string, list<array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null}>> Documents grouped by urgency level
     */
    public function getExpiringDocuments(int $days = 90): array
    {
        $now = new \DateTime();
        $endDate = (new \DateTime())->modify("+{$days} days");

        $qb = $this->documentRepository->createQueryBuilder('d')
            ->where('d.expiryDate IS NOT NULL')
            ->andWhere('d.expiryDate <= :endDate')
            ->andWhere('(d.snoozedUntil IS NULL OR d.snoozedUntil < :now)')
            ->setParameter('endDate', $endDate)
            ->setParameter('now', $now)
            ->orderBy('d.expiryDate', 'ASC');

        /** @var list<ComplianceDocument> $documents */
        $documents = $qb->getQuery()->getResult();

        // Buckets are derived from REMINDER_THRESHOLDS (90/60/30/14/7/0) so the
        // grouping can never drift out of sync with the reminder table.
        $grouped = [];
        $thresholdLevels = [];
        foreach (self::REMINDER_THRESHOLDS as $thresholdDays => $cfg) {
            $grouped[$cfg['level']] = [];
            $thresholdLevels[$thresholdDays] = $cfg['level'];
        }
        krsort($thresholdLevels);

        foreach ($documents as $doc) {
            $expiryDate = $doc->getExpiryDate();
            if ($expiryDate === null) {
                // The query filters expiryDate IS NOT NULL; nothing to report.
                continue;
            }
            $daysUntilExpiry = $now->diff($expiryDate)->days;
            $isPast = $expiryDate < $now;

            if ($isPast) {
                $grouped['expired'][] = $this->formatDocumentInfo($doc, $daysUntilExpiry, true);
                continue;
            }

            // Find the strictest threshold the document still fits within
            // (iterating descending means the LAST match is the tightest).
            $level = 'info';
            foreach ($thresholdLevels as $thresholdDays => $cfgLevel) {
                if ($daysUntilExpiry <= $thresholdDays) {
                    $level = $cfgLevel;
                }
            }
            $grouped[$level][] = $this->formatDocumentInfo($doc, $daysUntilExpiry);
        }

        return $grouped;
    }

    /**
     * Get expiring documents for a specific company
     *
     * @return list<array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null}>
     */
    public function getExpiringDocumentsForCompany(Company $company, int $days = 90): array
    {
        $now = new \DateTime();
        $endDate = (new \DateTime())->modify("+{$days} days");

        /** @var list<ComplianceDocument> $documents */
        $documents = $this->documentRepository->createQueryBuilder('d')
            ->where('d.company = :company')
            ->andWhere('d.expiryDate IS NOT NULL')
            ->andWhere('d.expiryDate <= :endDate')
            ->andWhere('(d.snoozedUntil IS NULL OR d.snoozedUntil < :now)')
            ->setParameter('company', $company)
            ->setParameter('endDate', $endDate)
            ->setParameter('now', $now)
            ->orderBy('d.expiryDate', 'ASC')
            ->getQuery()
            ->getResult();

        $docInfos = [];
        foreach ($documents as $doc) {
            $expiryDate = $doc->getExpiryDate();
            if ($expiryDate === null) {
                // The query filters expiryDate IS NOT NULL; nothing to report.
                continue;
            }
            $daysUntil = $now->diff($expiryDate)->days;
            $isPast = $expiryDate < $now;
            $docInfos[] = $this->formatDocumentInfo($doc, $daysUntil, $isPast);
        }

        return $docInfos;
    }

    /**
     * Get summary of expiring documents for dashboard
     *
     * @return array{total_expiring: int|float, expired: int, urgent: int, critical: int, warning: int, notice: int, info: int, critical_documents_expiring: int}
     */
    public function getExpirySummary(): array
    {
        $expiring = $this->getExpiringDocuments(90);
        
        return [
            'total_expiring' => array_sum(array_map('count', $expiring)),
            'expired' => count($expiring['expired']),
            'urgent' => count($expiring['urgent']),
            'critical' => count($expiring['critical']),
            'warning' => count($expiring['warning']),
            'notice' => count($expiring['notice']),
            'info' => count($expiring['info']),
            'critical_documents_expiring' => $this->countCriticalExpiring($expiring),
        ];
    }
    
    /**
     * Process reminders for all expiring documents
     *
     * @return array{processed: int, emails_sent: int, emails_failed: int, by_level: array<string, int>} Summary of reminders processed
     */
    public function processReminders(): array
    {
        $expiring = $this->getExpiringDocuments(90);
        $summary = [
            'processed' => 0,
            'emails_sent' => 0,
            'emails_failed' => 0,
            'by_level' => [],
        ];
        
        foreach ($expiring as $level => $docs) {
            $summary['by_level'][$level] = count($docs);
            
            foreach ($docs as $docInfo) {
                $summary['processed']++;
                
                // Send email for urgent/critical/expired
                if (in_array($level, ['expired', 'urgent', 'critical'])) {
                    $sent = $this->sendReminderEmail($docInfo, $level);
                    if ($sent) {
                        $summary['emails_sent']++;
                    } else {
                        $summary['emails_failed']++;
                    }
                }
            }
        }
        
        $this->logger->info('Compliance expiry reminders processed', $summary);
        
        return $summary;
    }
    
    /**
     * Snooze a document's reminder
     */
    public function snoozeReminder(
        ComplianceDocument $document,
        int $days,
        string $reason,
        string $snoozedBy
    ): void {
        $snoozeUntil = (new \DateTime())->modify("+{$days} days");
        
        $document->setSnoozedUntil($snoozeUntil);
        $document->setSnoozeReason($reason);
        $document->setSnoozedBy($snoozedBy);
        
        $this->entityManager->flush();
        
        $this->logger->info('Compliance reminder snoozed', [
            'document' => $document->getName(),
            'company' => $document->getCompany()?->getName(),
            'until' => $snoozeUntil->format('Y-m-d'),
            'by' => $snoozedBy,
        ]);
    }
    
    /**
     * Clear snooze on a document
     */
    public function clearSnooze(ComplianceDocument $document): void
    {
        $document->setSnoozedUntil(null);
        $document->setSnoozeReason(null);
        $document->setSnoozedBy(null);
        
        $this->entityManager->flush();
    }
    
    /**
     * Get companies with the most expiring documents
     *
     * @return list<array{company_id: int|null, company_name: string, total: int, expired: int, urgent: int, critical: int}>
     */
    public function getCompaniesWithExpiringDocuments(int $limit = 10): array
    {
        $expiring = $this->getExpiringDocuments(90);
        $byCompany = [];

        foreach ($expiring as $level => $docs) {
            foreach ($docs as $docInfo) {
                $companyId = $docInfo['company_id'];
                $companyName = $docInfo['company_name'];
                // A null company id is bucketed under '' exactly as the PHP
                // null-key coercion behaved before.
                $bucketKey = $companyId ?? '';

                if (!isset($byCompany[$bucketKey])) {
                    $byCompany[$bucketKey] = [
                        'company_id' => $companyId,
                        'company_name' => $companyName,
                        'total' => 0,
                        'expired' => 0,
                        'urgent' => 0,
                        'critical' => 0,
                    ];
                }

                $byCompany[$bucketKey]['total']++;
                if (in_array($level, ['expired', 'urgent', 'critical'], true)) {
                    $byCompany[$bucketKey][$level]++;
                }
            }
        }

        // Sort by urgency (expired + urgent + critical) then total
        usort($byCompany, function (array $a, array $b): int {
            $urgentA = $a['expired'] + $a['urgent'] + $a['critical'];
            $urgentB = $b['expired'] + $b['urgent'] + $b['critical'];
            if ($urgentA !== $urgentB) {
                return $urgentB <=> $urgentA;
            }
            return $b['total'] <=> $a['total'];
        });

        return array_slice($byCompany, 0, $limit);
    }
    
    /**
     * Get renewal calendar data
     *
     * @return array<string, array{month: string, documents: list<array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null}>, count: int}>
     */
    public function getRenewalCalendar(int $months = 6): array
    {
        $calendar = [];
        $now = new \DateTime();
        
        for ($i = 0; $i < $months; $i++) {
            $monthStart = (clone $now)->modify("+{$i} months")->modify('first day of this month');
            $monthEnd = (clone $monthStart)->modify('last day of this month');
            $monthKey = $monthStart->format('Y-m');
            
            $calendar[$monthKey] = [
                'month' => $monthStart->format('F Y'),
                'documents' => [],
                'count' => 0,
            ];
        }
        
        $expiring = $this->getExpiringDocuments($months * 31);
        foreach ($expiring as $docs) {
            foreach ($docs as $docInfo) {
                if ($docInfo['expiry_date'] === null) {
                    // Documents without an expiry date cannot be placed on the calendar.
                    continue;
                }
                $monthKey = $docInfo['expiry_date']->format('Y-m');
                if (isset($calendar[$monthKey])) {
                    $calendar[$monthKey]['documents'][] = $docInfo;
                    $calendar[$monthKey]['count']++;
                }
            }
        }

        return $calendar;
    }

    // Private helpers

    /**
     * @return array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null}
     */
    private function formatDocumentInfo(ComplianceDocument $doc, int $daysUntilExpiry, bool $isExpired = false): array
    {
        $company = $doc->getCompany();
        
        return [
            'id' => $doc->getId(),
            'document_name' => $doc->getName(),
            'document_type' => $doc->getDocumentType() ?? $doc->getName(),
            'company_id' => $company?->getId(),
            'company_name' => $company?->getName() ?? 'Unknown',
            'expiry_date' => $doc->getExpiryDate(),
            'days_until_expiry' => $isExpired ? -$daysUntilExpiry : $daysUntilExpiry,
            'is_expired' => $isExpired,
            'is_critical' => in_array($doc->getName(), self::CRITICAL_DOCUMENTS),
            'status' => $doc->getStatus(),
            'snoozed_until' => $doc->getSnoozedUntil(),
            'snooze_reason' => $doc->getSnoozeReason(),
        ];
    }
    
    /**
     * @param array<string, list<array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null}>> $expiring
     */
    private /**
 * @param array<string|int, mixed> $expiring
 */
function countCriticalExpiring(array $expiring): int
    {
        $count = 0;
        foreach ($expiring as $docs) {
            foreach ($docs as $docInfo) {
                if ($docInfo['is_critical']) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    /**
     * @param array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface|null, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null} $docInfo
     */
    private /**
 * @param array<string|int, mixed> $docInfo
 */
function sendReminderEmail(array $docInfo, string $level): bool
    {
        if (!$this->mailer) {
            $this->logger->warning('Compliance expiry email NOT sent — no mailer configured', [
                'document' => $docInfo['document_name'],
                'company' => $docInfo['company_name'],
                'level' => $level,
            ]);
            return false;
        }

        if ($docInfo['expiry_date'] === null) {
            $this->logger->warning('Compliance expiry email NOT sent — document has no expiry date', [
                'document' => $docInfo['document_name'],
                'company' => $docInfo['company_name'],
                'level' => $level,
            ]);
            return false;
        }

        try {
            $subject = match ($level) {
                'expired' => sprintf('[EXPIRED] %s for %s has expired', $docInfo['document_name'], $docInfo['company_name']),
                'urgent' => sprintf('[URGENT] %s for %s expires in %d days', $docInfo['document_name'], $docInfo['company_name'], $docInfo['days_until_expiry']),
                'critical' => sprintf('[ACTION REQUIRED] %s for %s expires in %d days', $docInfo['document_name'], $docInfo['company_name'], $docInfo['days_until_expiry']),
                default => sprintf('Compliance Document Reminder: %s', $docInfo['document_name']),
            };
            
            $body = $this->buildEmailBody($docInfo, $level);
            
            $email = (new Email())
                ->from($this->mailerFromAddress)
                ->to($this->adminEmail)
                ->subject($subject)
                ->html($body);
            
            $this->mailer->send($email);
            
            $this->logger->info('Compliance expiry email sent', [
                'document' => $docInfo['document_name'],
                'company' => $docInfo['company_name'],
                'level' => $level,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            // Log loudly — a swallowed send failure would hide a broken
            // reminder pipeline and the doc would never be chased up.
            $this->logger->error('FAILED to send compliance expiry email', [
                'error' => $e->getMessage(),
                'exception' => $e,
                'document' => $docInfo['document_name'],
                'company' => $docInfo['company_name'],
                'level' => $level,
                'to' => $this->adminEmail,
            ]);
            return false;
        }
    }
    
    /**
     * @param array{id: int|null, document_name: string|null, document_type: string|null, company_id: int|null, company_name: string, expiry_date: \DateTimeInterface, days_until_expiry: int, is_expired: bool, is_critical: bool, status: string|null, snoozed_until: \DateTimeInterface|null, snooze_reason: string|null} $docInfo
     */
    private /**
 * @param array<string|int, mixed> $docInfo
 */
function buildEmailBody(array $docInfo, string $level): string
    {
        $urgencyColor = match ($level) {
            'expired' => '#dc3545',
            'urgent' => '#fd7e14',
            'critical' => '#ffc107',
            default => '#17a2b8',
        };
        
        $statusText = match ($level) {
            'expired' => 'HAS EXPIRED',
            'urgent' => sprintf('expires in %d days', $docInfo['days_until_expiry']),
            'critical' => sprintf('expires in %d days', $docInfo['days_until_expiry']),
            default => 'requires attention',
        };
        
        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <div style="background: {$urgencyColor}; color: white; padding: 15px; text-align: center;">
                <h2 style="margin: 0;">Compliance Document Alert</h2>
            </div>
            <div style="padding: 20px; border: 1px solid #ddd; border-top: none;">
                <p><strong>Document:</strong> {$docInfo['document_name']}</p>
                <p><strong>Company:</strong> {$docInfo['company_name']}</p>
                <p><strong>Status:</strong> <span style="color: {$urgencyColor}; font-weight: bold;">{$statusText}</span></p>
                <p><strong>Expiry Date:</strong> {$docInfo['expiry_date']->format('F j, Y')}</p>
                
                <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
                
                <p><strong>Recommended Action:</strong></p>
                <ul>
                    <li>Contact the supplier to request an updated document</li>
                    <li>Review if renewal is required based on active contracts</li>
                    <li>Update the CRM once a new document is received</li>
                </ul>
                
                <p style="font-size: 12px; color: #555; margin-top: 20px;">
                    This is an automated reminder from the CRM Compliance Module.
                </p>
            </div>
        </div>
        HTML;
    }
}
