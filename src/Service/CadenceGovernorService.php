<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\EmailSend;
use App\Entity\EmailUnsubscribe;
use App\Entity\OutboundMessage;
use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cadence Governor Service
 *
 * Prevents burning leads by enforcing:
 * 1. Max touches per contact per 7/14/30 day window (cross-module: OutboundMessage + EmailSend)
 * 2. Global suppression list check (EmailUnsubscribe table — shared with Email Campaigns)
 * 3. Automatic stop on reply, bounce, complaint, unsubscribe
 * 4. Send-time bounds: only within business hours (local 9am–4pm)
 * 5. Time-zone inference from geography with UTC fallback
 * 6. Delayed soft-failure: non-reply becomes censored outcome after window
 *
 * Cross-module awareness:
 *   - Checks EmailUnsubscribe before allowing any send (Gap 1 fix)
 *   - Counts EmailSend records in cadence windows so that contacts in
 *     both Email Campaigns and Autonomous Sales don't get over-emailed (Gap 2 fix)
 *
 * Math:
 *   reply_window = REPLY_WINDOW_DAYS days after sentAt
 *   soft_failure_weight = 0.30 (β += 0.30) applied once after window expires
 */
class CadenceGovernorService
{
    // ==================== CADENCE LIMITS ====================
    public const MAX_TOUCHES_7_DAYS  = 2;
    public const MAX_TOUCHES_14_DAYS = 3;
    public const MAX_TOUCHES_30_DAYS = 5;

    // Business-hours window (sender-local, 24h format)
    public const BIZ_HOUR_START = 9;
    public const BIZ_HOUR_END   = 16; // 4pm

    // Reply window (days) — non-reply is censored until this expires
    public const REPLY_WINDOW_DAYS  = 10;

    // Stop-event types — any of these halts further sends to the contact
    public const STOP_EVENTS = ['replied', 'bounced', 'unsubscribe', 'complaint', 'spam'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        /** Reserved for direct outbound queries; DQL currently goes through the entity manager. */
        protected OutboundMessageRepository $outboundRepo,
        private LoggerInterface $logger,
    ) {}

    /**
     * Check whether a contact is eligible to receive a new outbound email.
     *
     * Performs three layers of checks:
     * 1. Global suppression list (EmailUnsubscribe) — shared with Email Campaigns
     * 2. Stop events from previous outbound messages (reply, bounce, complaint)
     * 3. Cross-module cadence limits (OutboundMessage + EmailSend combined)
     *
     * @return array{allowed: bool, reason: string|null, nextAllowedAt: \DateTimeInterface|null}
     */
    public function canSendTo(Contact $contact): array
    {
        $email = $contact->getEmail();

        // ==================== GLOBAL SUPPRESSION LIST ====================
        // Check EmailUnsubscribe table — this is the shared suppression list
        // used by both Email Campaigns and Autonomous Sales.
        if ($email) {
            $suppressed = $this->entityManager->getRepository(EmailUnsubscribe::class)
                ->findOneBy(['email' => $email]);

            if ($suppressed) {
                return [
                    'allowed' => false,
                    'reason' => sprintf(
                        'Contact is on global suppression list (reason: %s, since: %s)',
                        $suppressed->getReason() ?? 'unknown',
                        $suppressed->getUnsubscribedAt()?->format('Y-m-d') ?? 'unknown'
                    ),
                    'nextAllowedAt' => null,
                ];
            }
        }

        // ==================== OUTBOUND STOP RULES ====================
        // Fetch recent outbound messages for this contact
        $recentMessages = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(OutboundMessage::class, 'm')
            ->where('m.contact = :contact')
            ->setParameter('contact', $contact)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        /** @var list<OutboundMessage> $recentMessages */
        foreach ($recentMessages as $msg) {
            if (in_array($msg->getStatus(), self::STOP_EVENTS, true)) {
                return [
                    'allowed' => false,
                    'reason' => sprintf('Contact has stop event: %s', $msg->getStatus()),
                    'nextAllowedAt' => null,
                ];
            }
            // Also check reply classification
            $classification = $msg->getReplyClassification();
            if ($classification && in_array($classification, ['not_interested', 'unsubscribe', 'spam'], true)) {
                return [
                    'allowed' => false,
                    'reason' => sprintf('Contact replied with: %s', $classification),
                    'nextAllowedAt' => null,
                ];
            }
        }

        // ==================== CROSS-MODULE CADENCE LIMITS ====================
        // Count sends from BOTH modules (OutboundMessage + EmailSend) so a
        // contact in both a marketing drip and autonomous outreach doesn't
        // get over-emailed.
        $now = new \DateTime();
        $lastSentAt = null;

        // --- Outbound (Autonomous Sales) send dates ---
        $outboundDates = [];
        foreach ($recentMessages as $msg) {
            $sentAt = $msg->getSentAt() ?? $msg->getCreatedAt();
            if ($sentAt) {
                $outboundDates[] = $sentAt;
            }
        }

        // --- Campaign (Email Campaigns) send dates ---
        /** @var list<array{sentAt: \DateTimeInterface|null}> $campaignSendDates */
        $campaignSendDates = $this->entityManager->createQueryBuilder()
            ->select('es.sentAt')
            ->from(EmailSend::class, 'es')
            ->where('es.contact = :contact')
            ->andWhere('es.sentAt IS NOT NULL')
            ->andWhere('es.sentAt > :since')
            ->setParameter('contact', $contact)
            ->setParameter('since', (clone $now)->modify('-30 days'))
            ->getQuery()
            ->getArrayResult();

        $allDates = $outboundDates;
        foreach ($campaignSendDates as $row) {
            if ($row['sentAt'] instanceof \DateTimeInterface) {
                $allDates[] = $row['sentAt'];
            }
        }

        /** @var \DateTimeInterface|null $lastSentAt */
        $lastSentAt = null;

        // Count combined sends per window
        $count7  = 0;
        $count14 = 0;
        $count30 = 0;

        foreach ($allDates as $sentAt) {
            if (!$lastSentAt || $sentAt > $lastSentAt) {
                $lastSentAt = $sentAt;
            }

            $daysDiff = (int) $now->diff($sentAt)->days;
            if ($daysDiff <= 7)  $count7++;
            if ($daysDiff <= 14) $count14++;
            if ($daysDiff <= 30) $count30++;
        }

        if ($count7 >= self::MAX_TOUCHES_7_DAYS) {
            $nextAllowed = $lastSentAt !== null ? \DateTime::createFromInterface($lastSentAt)->modify('+7 days') : null;
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Max %d touches in 7 days reached (%d sent across all email modules)',
                    self::MAX_TOUCHES_7_DAYS, $count7
                ),
                'nextAllowedAt' => $nextAllowed,
            ];
        }

        if ($count14 >= self::MAX_TOUCHES_14_DAYS) {
            $nextAllowed = $lastSentAt !== null ? \DateTime::createFromInterface($lastSentAt)->modify('+14 days') : null;
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Max %d touches in 14 days reached (%d sent across all email modules)',
                    self::MAX_TOUCHES_14_DAYS, $count14
                ),
                'nextAllowedAt' => $nextAllowed,
            ];
        }

        if ($count30 >= self::MAX_TOUCHES_30_DAYS) {
            $nextAllowed = $lastSentAt !== null ? \DateTime::createFromInterface($lastSentAt)->modify('+30 days') : null;
            return [
                'allowed' => false,
                'reason' => sprintf(
                    'Max %d touches in 30 days reached (%d sent across all email modules)',
                    self::MAX_TOUCHES_30_DAYS, $count30
                ),
                'nextAllowedAt' => $nextAllowed,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'nextAllowedAt' => null];
    }

    /**
     * Check if NOW is within business hours for a contact's timezone.
     *
     * @return array{inWindow: bool, timezone: string, localHour: int, dayOfWeek: int}
     */
    public function isWithinBusinessHours(Contact $contact): array
    {
        $tz = $this->inferTimezone($contact);
        $localNow = new \DateTime('now', new \DateTimeZone($tz));
        $hour = (int) $localNow->format('G');
        $dayOfWeek = (int) $localNow->format('N'); // 1=Mon, 7=Sun

        $inWindow = $hour >= self::BIZ_HOUR_START
            && $hour < self::BIZ_HOUR_END
            && $dayOfWeek <= 5; // Mon-Fri

        return [
            'inWindow' => $inWindow,
            'timezone' => $tz,
            'localHour' => $hour,
            'dayOfWeek' => $dayOfWeek,
        ];
    }

    /**
     * Infer timezone from contact's company geography.
     * Falls back to CET (Morocco / Western Europe) if unknown.
     */
    private const TIMEZONE_MAP = [
        // EU countries → Europe/Berlin
        '/\b(germany|france|spain|italy|netherlands|belgium|austria|poland|czech|sweden|norway|denmark|finland|switzerland)\b/' => 'Europe/Berlin',
        // UK → Europe/London
        '/\buk|united kingdom|england|scotland|wales\b/' => 'Europe/London',
        // US Pacific
        '/\b(california|washington|oregon|nevada)\b/' => 'America/Los_Angeles',
        // US Eastern
        '/\b(new york|boston|virginia|florida|carolina|georgia|maryland|pennsylvania|ohio|michigan|illinois|texas|colorado)\b/' => 'America/New_York',
        // US fallback
        '/\b(usa|united states)\b/' => 'America/Chicago',
        // Asia
        '/\b(china|hong kong|taiwan|singapore)\b/' => 'Asia/Shanghai',
        '/\bjapan\b/' => 'Asia/Tokyo',
        '/\bindia\b/' => 'Asia/Kolkata',
        '/\bkorea\b/' => 'Asia/Seoul',
        // MENA
        '/\b(uae|dubai|abu dhabi|saudi|qatar|bahrain|oman|kuwait)\b/' => 'Asia/Dubai',
        '/\bmorocco|casablanca|tangier|rabat\b/' => 'Africa/Casablanca',
        '/\bturkey|istanbul|ankara\b/' => 'Europe/Istanbul',
        // Africa
        '/\b(south africa|johannesburg|cape town)\b/' => 'Africa/Johannesburg',
    ];

    private function inferTimezone(Contact $contact): string
    {
        $company = $contact->getCompany();
        if (!$company) return 'Africa/Casablanca';

        $location = strtolower($company->getPhysicalSite() ?? $company->getRegion() ?? '');

        foreach (self::TIMEZONE_MAP as $pattern => $timezone) {
            if (preg_match($pattern, $location)) {
                return $timezone;
            }
        }

        return 'Africa/Casablanca'; // default (Starz Electronics timezone)
    }

    /**
     * Process delayed soft failures for messages past their reply window.
     *
     * Called by daily cron. Finds messages sent > REPLY_WINDOW_DAYS ago
     * with no reply/click/bounce, and applies a soft β penalty to the
     * subject-line arm. The penalty is censored (lower than a real failure)
     * because absence-of-reply is weaker signal than explicit negative.
     *
     * @return list<array{messageId: int|null, contactId: int|null, armId: int|null, sentAt: string|null}> List of messages that had soft failure applied
     */
    public function processDelayedSoftFailures(ThompsonSamplerService $sampler): array
    {
        $cutoff = (new \DateTime())->modify(sprintf('-%d days', self::REPLY_WINDOW_DAYS));

        // Find messages: sent before cutoff, not replied, not bounced, soft failure not yet applied
        /** @var list<OutboundMessage> $candidates */
        $candidates = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(OutboundMessage::class, 'm')
            ->where('m.sentAt IS NOT NULL')
            ->andWhere('m.sentAt < :cutoff')
            ->andWhere('m.status IN (:eligibleStatuses)')
            ->andWhere('m.softFailureApplied = false')
            ->andWhere('m.subjectArm IS NOT NULL')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('eligibleStatuses', [
                OutboundMessage::STATUS_SENT,
                OutboundMessage::STATUS_DELIVERED,
                OutboundMessage::STATUS_OPENED,  // opened but never replied
            ])
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $processed = [];
        foreach ($candidates as $msg) {
            $arm = $msg->getSubjectArm();
            if (!$arm) continue;

            // Apply soft failure: β += WEIGHT_NO_RESPONSE (0.30)
            $sampler->recordWeightedOutcome((int) $arm->getId(), 'no_response');

            // Also apply to value-prop arm if tracked
            $vpArmId = $msg->getValuePropArmId();
            if ($vpArmId) {
                $sampler->recordWeightedOutcome($vpArmId, 'no_response');
            }

            $msg->setSoftFailureApplied(true);
            $this->entityManager->persist($msg);

            $processed[] = [
                'messageId' => $msg->getId(),
                'contactId' => $msg->getContact()?->getId(),
                'armId' => $arm->getId(),
                'sentAt' => $msg->getSentAt()?->format('Y-m-d'),
            ];
        }

        $this->entityManager->flush();

        $this->logger->info('Processed delayed soft failures', ['count' => count($processed)]);

        return $processed;
    }
}
