<?php

namespace App\Service;

use App\Entity\Webinar;
use App\Entity\WebinarAttendee;
use App\Entity\Contact;
use App\Repository\WebinarRepository;
use App\Repository\WebinarAttendeeRepository;
use Doctrine\ORM\EntityManagerInterface;

class WebinarService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WebinarRepository $webinarRepository,
        private WebinarAttendeeRepository $webinarAttendeeRepository
    ) {}

    /**
     * Register a contact for a webinar
     */
    public function registerAttendee(Webinar $webinar, Contact $contact, string $email, string $name): WebinarAttendee
    {
        $attendee = new WebinarAttendee();
        $attendee->setWebinar($webinar);
        $attendee->setContact($contact);
        $attendee->setCompany($contact->getCompany());
        $attendee->setEmail($email);
        $attendee->setName($name);
        $attendee->setRegisteredAt(new \DateTime());

        // Increment registered count
        $webinar->setRegisteredCount($webinar->getRegisteredCount() + 1);

        $this->entityManager->persist($attendee);
        $this->entityManager->persist($webinar);
        $this->entityManager->flush();

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
     * Bulk import attendees from CSV
     */
    public function importAttendeesFromCSV(Webinar $webinar, string $csvPath): int
    {
        $imported = 0;
        
        if (($handle = fopen($csvPath, 'r')) !== false) {
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== false) {
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
