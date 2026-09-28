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
        private WebinarRepository $webinarRepository,
        private WebinarAttendeeRepository $webinarAttendeeRepository,
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
    public function registerAttendee(Webinar $webinar, ?Contact $contact, string $email, string $name): WebinarAttendee
    {
        $email = strtolower(trim($email));

        $existing = $this->entityManager->getRepository(WebinarAttendee::class)
            ->findOneBy(['webinar' => $webinar, 'email' => $email]);
        if ($existing !== null) {
            return $existing;
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
        // Free-text company names from external registrants are NOT matched
        // to Company entities (arbitrary name matching would pollute CRM
        // data); converting an attendee to a company is an internal workflow.

        // Increment registered count
        $webinar->setRegisteredCount($webinar->getRegisteredCount() + 1);

        $this->entityManager->persist($attendee);
        $this->entityManager->persist($webinar);
        $this->entityManager->flush();

        // Send confirmation email
        $this->sendConfirmationEmail($attendee);

        return $attendee;
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

        $this->mailer->send($email);
        
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

        $this->mailer->send($email);
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
                    $attendee = new WebinarAttendee();
                    $attendee->setWebinar($webinar);
                    $attendee->setEmail($email);
                    $attendee->setName($name);
                    $attendee->setAttended($attended);
                    $attendee->setRegisteredAt(new \DateTime());
                    
                    $this->entityManager->persist($attendee);
                    $imported++;
                }
            }
            
            fclose($handle);
            $this->entityManager->flush();
        }
        
        return $imported;
    }
}
