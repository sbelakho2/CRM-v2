<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\EmailUnsubscribe;
use App\Entity\OutboundMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Canonical pre-send eligibility gate for EVERY campaign email path
 * (manual send, scheduler, Messenger handler, drip progression). No sender
 * may bypass it: sendToContact() evaluates this policy as its first business
 * step, so an unsubscribed/bounced/cadence-exceeded contact can never be
 * emailed regardless of which route enqueued the send.
 */
final class EmailSendPolicy
{
    /** Max combined emails (campaigns + autonomous outbound) per 7 days. */
    private const CROSS_MODULE_MAX_7_DAYS = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return SendEligibility
     */
    public function evaluate(Contact $contact, EmailCampaign $campaign): SendEligibility
    {
        $email = $contact->getEmail();

        if ($email === null || trim($email) === '') {
            return SendEligibility::skipped('no_email');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return SendEligibility::skipped('invalid_email');
        }

        if ($contact->isArchived()) {
            return SendEligibility::skipped('contact_archived');
        }

        if (!$campaign->isActive()) {
            return SendEligibility::skipped('campaign_inactive');
        }

        // Global suppression list.
        $unsubscribed = $this->entityManager->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $email]);
        if ($unsubscribed !== null) {
            return SendEligibility::skipped('unsubscribed');
        }

        // Hard-bounce suppression: any bounced send in the last 90 days
        // disqualifies the address.
        $bounced = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(es.id)')
            ->from(EmailSend::class, 'es')
            ->where('es.emailAddress = :email')
            ->andWhere('es.bounced = true')
            ->andWhere('es.sentAt > :since')
            ->setParameter('email', $email)
            ->setParameter('since', (new \DateTime())->modify('-90 days'))
            ->getQuery()
            ->getSingleScalarResult();
        if ($bounced > 0) {
            return SendEligibility::skipped('hard_bounce_suppression');
        }

        // Cross-module cadence (Autonomous Sales outbound + campaigns).
        $sevenDaysAgo = (new \DateTime())->modify('-7 days');

        $outboundCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(om.id)')
            ->from(OutboundMessage::class, 'om')
            ->where('om.contact = :contact')
            ->andWhere('om.sentAt IS NOT NULL')
            ->andWhere('om.sentAt > :since')
            ->setParameter('contact', $contact)
            ->setParameter('since', $sevenDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        $campaignCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(es.id)')
            ->from(EmailSend::class, 'es')
            ->where('es.contact = :contact')
            ->andWhere('es.status = :sent')
            ->andWhere('es.sentAt IS NOT NULL')
            ->andWhere('es.sentAt > :since')
            ->setParameter('contact', $contact)
            ->setParameter('sent', EmailSend::STATUS_SENT)
            ->setParameter('since', $sevenDaysAgo)
            ->getQuery()
            ->getSingleScalarResult();

        if ($outboundCount + $campaignCount >= self::CROSS_MODULE_MAX_7_DAYS) {
            return SendEligibility::skipped('cadence_limit');
        }

        return SendEligibility::allowed();
    }
}

/**
 * @method bool isAllowed()
 * @method string|null reason()
 */
final class SendEligibility
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
    ) {}

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function skipped(string $reason): self
    {
        return new self(false, $reason);
    }
}
