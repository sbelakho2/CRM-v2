<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Webinar;
use App\Entity\WebinarAttendee;
use App\Entity\Contact;
use App\Repository\WebinarRepository;
use App\Repository\WebinarAttendeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class WebinarService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private \Symfony\Component\Mailer\Transport\TransportInterface $mailerTransport,
        private WebinarRepository $webinarRepository,
        private WebinarAttendeeRepository $webinarAttendeeRepository,
        private \Psr\Log\LoggerInterface $logger,
        private MailerInterface $mailer,
        private string $mailerFromAddress,
        private string $mailerFromName
    ) {}

    /**
     * Register a contact for a webinar and send confirmation email
     */
    /**
     * Register an attendee. Idempotent per (webinar, email): an existing
     * registration is returned unchanged — repeat submissions must not
     * create duplicate rows or inflate the registered count.
     *
     * $contact is OPTIONAL: external registrants are not forced into the
     * CRM contact model (a Contact requires a Company); a contact link is
     * only set when the email matches an existing CRM contact. Conversion
     * of an external attendee into a full contact is a separate internal
     * workflow.
     */
    public function registerAttendee(Webinar $webinar, ?Contact $contact, string $email, string $name, ?string $companyName = null): WebinarAttendee
    {
        $email = strtolower(trim($email));

        // ONE transaction owns the whole admission decision: webinar row
        // lock → duplicate check → LIVE capacity count → insert → counter.
        // Previously the controller locked+checked, closed its transaction,
        // and only then registered — two final-seat requests could both
        // pass the lock sequentially and both register.
        $attendeeId = $this->entityManager->wrapInTransaction(
            function () use ($webinar, $contact, $email, $name, $companyName): ?int {
                $conn = $this->entityManager->getConnection();

                // Lock the webinar row.
                $conn->executeQuery(
                    'SELECT id FROM webinars WHERE id = :id FOR UPDATE',
                    ['id' => $webinar->getId()]
                );

                // Duplicate registration is idempotent.
                $existingId = $conn->fetchOne(
                    'SELECT id FROM webinar_attendees WHERE webinar_id = :wid AND email = :email',
                    ['wid' => $webinar->getId(), 'email' => $email]
                );
                if ($existingId !== null && $existingId !== false) {
                    return (int) $existingId;
                }

                // LIVE capacity: COUNT(*) under the lock, NOT the
                // denormalized registeredCount — CSV imports and historical
                // duplicate-merge migrations do not reliably maintain that
                // counter. The counter stays as a display best-effort.
                $archivedAt = $webinar->getArchivedAt();
                $status = $webinar->getStatus();
                $scheduledDate = $webinar->getScheduledDate();
                $max = $webinar->getMaxAttendees();

                if ($archivedAt !== null
                    || $status === \App\Entity\Webinar::STATUS_COMPLETED
                    || $status === \App\Entity\Webinar::STATUS_CANCELLED
                    || ($scheduledDate !== null && $scheduledDate <= new \DateTime())) {
                    throw new \RuntimeException('This webinar is no longer accepting registrations.');
                }

                if ($max !== null && $max > 0) {
                    $liveCount = (int) $conn->fetchOne(
                        'SELECT COUNT(*) FROM webinar_attendees WHERE webinar_id = :wid',
                        ['wid' => $webinar->getId()]
                    );
                    if ($liveCount >= $max) {
                        throw new \RuntimeException('This webinar is full.');
                    }
                }

                $attendee = new WebinarAttendee();
                $attendee->setWebinar($webinar);
                $attendee->setEmail($email);
                $attendee->setName($name);
                $attendee->setRegisteredAt(new \DateTime());

                if ($contact !== null) {
                    $attendee->setContact($contact);
                    $attendee->setCompany($contact->getCompany());
                }
                if ($companyName !== null && trim($companyName) !== '') {
                    $attendee->setCompanyName(trim($companyName));
                }

                $this->entityManager->persist($attendee);

                // Display counter: best-effort maintenance alongside the
                // authoritative COUNT above.
                $conn->executeStatement(
                    'UPDATE webinars SET registered_count = registered_count + 1 WHERE id = :id',
                    ['id' => $webinar->getId()]
                );

                $this->entityManager->flush();

                return $attendee->getId();
            }
        );

        // Re-fetch outside the transaction. The raw connection is used so a
        // manager that a failed flush might have closed cannot break the
        // idempotent path (the ORM query through a closed manager was the
        // bug the email code already had to fix once).
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM webinar_attendees WHERE id = :id',
            ['id' => $attendeeId]
        );

        $attendee = $this->entityManager->getRepository(WebinarAttendee::class)->find($attendeeId);
        if ($attendee === null) {
            throw new \RuntimeException('Registration could not be confirmed.');
        }

        $this->ensureConfirmationEmail($attendee);

        return $attendee;
    }

    /**
     * Send the confirmation email unless one was already delivered.
     *
     * Registration MUST succeed independently of mail delivery: a mailer
     * outage turns into a logged, retryable confirmation gap — never a 500
     * for the registrant, and repeated submissions repair the gap.
     */
    private function ensureConfirmationEmail(WebinarAttendee $attendee): void
    {
        if ($attendee->getConfirmationSentAt() !== null) {
            return;
        }

        try {
            $this->sendConfirmationEmail($attendee);
            // confirmationSentAt is set ONLY after the synchronous transport
            // accepted the message — MailerInterface would queue it async in
            // production and mark a delivery that may never happen.
            $attendee->setConfirmationSentAt(new \DateTime());
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Registration stands; the confirmation stays retryable via
            // repeated registration attempts or a future resend worker.
            $this->logger->error('Failed to send webinar confirmation email', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Mark attendee as attended
     */
    public function markAttended(WebinarAttendee $attendee): void
    {
        if (!$attendee->isAttended()) {
            $attendee->setAttended(true);
            
            // Increment attended count
            $webinar = $attendee->getWebinar();
            $webinar->setAttendedCount($webinar->getAttendedCount() + 1);

            $this->entityManager->persist($attendee);
            $this->entityManager->persist($webinar);
            $this->entityManager->flush();
        }
    }

    /**
     * Mark follow-up sent for attendee
     */
    public function markFollowUpSent(WebinarAttendee $attendee): void
    {
        $attendee->setFollowUpSent(true);
        $this->entityManager->persist($attendee);
        $this->entityManager->flush();
    }

    /**
     * Send follow-up email to attendee
     */
    public function sendFollowUpEmail(WebinarAttendee $attendee): void
    {
        $webinar = $attendee->getWebinar();
        
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($attendee->getEmail())
            ->subject('Thank you for attending: ' . $webinar->getTitle())
            ->htmlTemplate('emails/webinar_followup.html.twig')
            ->context([
                'attendee' => $attendee,
                'webinar' => $webinar,
            ]);

        $this->mailerTransport->send($email);
        
        // Mark as sent
        $this->markFollowUpSent($attendee);
    }

    /**
     * Get upcoming webinars
     */
    public function getUpcomingWebinars(?string $language = null): array
    {
        return $this->webinarRepository->findUpcoming($language);
    }

    /**
     * Get past webinars
     */
    public function getPastWebinars(?string $language = null): array
    {
        return $this->webinarRepository->findPast($language);
    }

    /**
     * Get webinar statistics
     */
    public function getWebinarStats(Webinar $webinar): array
    {
        $attendees = $webinar->getAttendees();
        $totalRegistered = $webinar->getRegisteredCount();
        $totalAttended = $webinar->getAttendedCount();
        
        $followUpsSent = 0;
        foreach ($attendees as $attendee) {
            if ($attendee->isFollowUpSent()) {
                $followUpsSent++;
            }
        }

        return [
            'registered' => $totalRegistered,
            'attended' => $totalAttended,
            'attendance_rate' => $totalRegistered > 0 ? ($totalAttended / $totalRegistered) * 100 : 0,
            'follow_ups_sent' => $followUpsSent,
            'follow_up_rate' => $totalAttended > 0 ? ($followUpsSent / $totalAttended) * 100 : 0,
        ];
    }

    /**
     * Get attendees who need follow-up
     */
    public function getAttendeesNeedingFollowUp(Webinar $webinar): array
    {
        return $this->webinarAttendeeRepository->findNeedingFollowUp($webinar);
    }

    /**
     * Send confirmation email to webinar attendee
     */
    private function sendConfirmationEmail(WebinarAttendee $attendee): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
            ->to($attendee->getEmail())
            ->subject('Webinar Registration Confirmation - ' . $attendee->getWebinar()->getTitle())
            ->htmlTemplate('emails/webinar_registration.html.twig')
            ->context([
                'attendee' => $attendee,
                'webinar' => $attendee->getWebinar(),
            ]);

        $this->mailerTransport->send($email);
    }

    /**
     * Bulk import attendees from CSV
     */
    public function importAttendeesFromCSV(Webinar $webinar, string $csvPath): int
    {
        $imported = 0;
        
        if (($handle = fopen($csvPath, 'r')) !== false) {
            // Skip header row
            fgetcsv($handle, 0, ',', '"', '\\');
            
            while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                // Assuming CSV format: email, name, attended
                $email = $data[0] ?? null;
                $name = $data[1] ?? null;
                $attended = ($data[2] ?? 'no') === 'yes';
                
                if ($email && $name) {
                    // Idempotent import: existing (webinar, email) rows are
                    // updated (attended flag) rather than duplicated.
                    $existing = $this->entityManager->getRepository(WebinarAttendee::class)
                        ->findOneBy(['webinar' => $webinar, 'email' => strtolower(trim($email))]);
                    if ($existing !== null) {
                        if ($attended && !$existing->isAttended()) {
                            $existing->setAttended(true);
                        }
                        continue;
                    }

                    $attendee = new WebinarAttendee();
                    $attendee->setWebinar($webinar);
                    $attendee->setEmail(strtolower(trim($email)));
                    $attendee->setName($name);
                    $attendee->setAttended($attended);
                    $attendee->setRegisteredAt(new \DateTime());

                    $this->entityManager->persist($attendee);
                    $imported++;
                }
            }

            fclose($handle);
            $this->entityManager->flush();

            // Recompute the denormalized display counters from LIVE rows —
            // imports bypass the registration path that maintains them, and
            // attendance flags arrive in the CSV itself. (Admission capacity
            // uses the live COUNT, so this is display hygiene, not correctness.)
            $this->recomputeWebinarCounters($webinar);
        }

        return $imported;
    }

    private function recomputeWebinarCounters(Webinar $webinar): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement(
            'UPDATE webinars SET
                registered_count = (SELECT COUNT(*) FROM webinar_attendees WHERE webinar_id = :id),
                attended_count = (SELECT COUNT(*) FROM webinar_attendees WHERE webinar_id = :id AND attended = 1)
             WHERE id = :id',
            ['id' => $webinar->getId()]
        );
    }
}
