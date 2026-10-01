<?php

namespace App\Controller;

use App\Entity\MeetingSlot;
use App\Entity\User;
use App\Form\MeetingBookingType;
use App\Form\MeetingSlotType;
use App\Repository\MeetingSlotRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

#[Route('/meetings')]
class MeetingSchedulerController extends AbstractController
{
    public function __construct(
        private MeetingSlotRepository $slotRepository,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private MailerInterface $mailer,
        private string $mailerFromAddress,
        private string $mailerFromName,
        private \Psr\Log\LoggerInterface $logger
    ) {}
    
    /**
     * My meetings dashboard (authenticated)
     */
    #[Route('', name: 'meeting_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }

        $today = $this->slotRepository->findTodayByUser($user);
        $upcoming = $this->slotRepository->findBookedByUser($user, true);
        $available = $this->slotRepository->findAvailableByUser($user);
        $statistics = $this->slotRepository->getStatistics($user);
        
        return $this->render('meeting/index.html.twig', [
            'today' => $today,
            'upcoming' => $upcoming,
            'available' => $available,
            'statistics' => $statistics,
            'meetingTypes' => MeetingSlot::getMeetingTypes(),
        ]);
    }
    
    /**
     * Calendar view of slots
     */
    #[Route('/calendar', name: 'meeting_calendar', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function calendar(): Response
    {
        return $this->render('meeting/calendar.html.twig', [
            'meetingTypes' => MeetingSlot::getMeetingTypes(),
        ]);
    }
    
    /**
     * API endpoint for calendar slots
     */
    #[Route('/api/slots', name: 'meeting_api_slots', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function apiSlots(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }
        $start = $this->parseDateParam($request->query->get('start'), 'now');
        if ($start === null) {
            return $this->json(['error' => 'Invalid start date'], 400);
        }
        $end = $this->parseDateParam($request->query->get('end'), '+30 days');
        if ($end === null) {
            return $this->json(['error' => 'Invalid end date'], 400);
        }

        /** @var list<MeetingSlot> $slots */
        $slots = $this->slotRepository->findByUserAndDateRange($user, $start, $end);
        
        $events = [];
        foreach ($slots as $slot) {
            $slotStart = $slot->getStartTime();
            $slotEnd = $slot->getEndTime();
            if ($slotStart === null || $slotEnd === null) {
                continue;
            }
            $status = $slot->getStatus() ?? '';
            $events[] = [
                'id' => $slot->getId(),
                'title' => $slot->getTitle(),
                'start' => $slotStart->format('c'),
                'end' => $slotEnd->format('c'),
                'backgroundColor' => $this->getStatusColor($status),
                'borderColor' => $this->getStatusColor($status),
                'extendedProps' => [
                    'status' => $slot->getStatus(),
                    'meetingType' => $slot->getMeetingType(),
                    'bookedBy' => $slot->getBookedByName(),
                    'duration' => $slot->getDurationMinutes(),
                ],
            ];
        }
        
        return new JsonResponse($events);
    }
    
    /**
     * Create new slot
     */
    #[Route('/new', name: 'meeting_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $slot = new MeetingSlot();
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }
        $slot->setOwner($user);
        
        // Pre-fill from query params if provided
        if ($startTime = $request->query->get('start')) {
            $parsedStart = $this->parseDateParam($startTime);
            if ($parsedStart === null) {
                $this->addFlash('error', 'Invalid start date.');
                return $this->redirectToRoute('meeting_index');
            }
            $slot->setStartTime($parsedStart);
        }
        
        $form = $this->createForm(MeetingSlotType::class, $slot);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Calculate end time from duration
            $startTime = $slot->getStartTime();
            if ($startTime === null) {
                throw $this->createNotFoundException('Meeting slot has no start time.');
            }
            $endTime = $startTime->modify("+{$slot->getDurationMinutes()} minutes");
            $slot->setEndTime($endTime);

            // Check for conflicts
            $conflicts = $this->slotRepository->findConflicts(
                $user,
                $startTime,
                $endTime
            );
            
            if (!empty($conflicts)) {
                $this->addFlash('error', $this->translator->trans('meeting.flash.conflict'));
                return $this->render('meeting/new.html.twig', [
                    'form' => $form,
                ]);
            }
            
            $this->slotRepository->save($slot, true);
            
            $this->addFlash('success', $this->translator->trans('meeting.flash.created'));
            return $this->redirectToRoute('meeting_index');
        }
        
        return $this->render('meeting/new.html.twig', [
            'form' => $form,
        ]);
    }
    
    /**
     * View slot details
     */
    #[Route('/{id}', name: 'meeting_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(MeetingSlot $slot): Response
    {
        if ($slot->getOwner() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        return $this->render('meeting/show.html.twig', [
            'slot' => $slot,
        ]);
    }
    
    /**
     * Edit slot
     */
    #[Route('/{id}/edit', name: 'meeting_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(MeetingSlot $slot, Request $request): Response
    {
        if ($slot->getOwner() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        $form = $this->createForm(MeetingSlotType::class, $slot);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $editStartTime = $slot->getStartTime();
            if ($editStartTime === null) {
                throw $this->createNotFoundException('Meeting slot has no start time.');
            }
            $editEndTime = $editStartTime->modify("+{$slot->getDurationMinutes()} minutes");
            $slot->setEndTime($editEndTime);
            
            $this->em->flush();
            
            $this->addFlash('success', $this->translator->trans('meeting.flash.updated'));
            return $this->redirectToRoute('meeting_show', ['id' => $slot->getId()]);
        }
        
        return $this->render('meeting/edit.html.twig', [
            'slot' => $slot,
            'form' => $form,
        ]);
    }
    
    /**
     * Delete slot
     */
    #[Route('/{id}/delete', name: 'meeting_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(MeetingSlot $slot, Request $request): Response
    {
        if ($slot->getOwner() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        $deleteToken = $request->request->get('_token');
        if (\is_string($deleteToken) && $this->isCsrfTokenValid('delete' . $slot->getId(), $deleteToken)) {
            // Booked slots carry customer booking history (name/email/phone/
            // company/notes): once ever booked, they are ARCHIVED, never
            // hard-deleted — same preservation model as companies/contacts.
            if ($slot->getBookedByEmail() !== null || $slot->getBookedAt() !== null) {
                $slot->setStatus(\App\Entity\MeetingSlot::STATUS_CANCELLED);
                $this->em->flush();
                $this->addFlash('warning', 'Meeting archived (kept for booking history) rather than deleted.');
            } else {
                // Pristine, never-booked availability slots may be removed.
                $this->slotRepository->remove($slot, true);
                $this->addFlash('success', $this->translator->trans('meeting.flash.deleted'));
            }
        }
        
        return $this->redirectToRoute('meeting_index');
    }
    
    /**
     * Cancel a booked meeting
     */
    #[Route('/{id}/cancel', name: 'meeting_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(MeetingSlot $slot, Request $request): Response
    {
        if ($slot->getOwner() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        
        $cancelToken = $request->request->get('_token');
        if (\is_string($cancelToken) && $this->isCsrfTokenValid('cancel' . $slot->getId(), $cancelToken)) {
            $bookerEmail = $slot->getBookedByEmail();
            $slot->cancel();
            $this->em->flush();
            
            if ($bookerEmail !== null && $bookerEmail !== '') {
                try {
                    $this->mailer->send(
                        (new TemplatedEmail())
                            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                            ->to($bookerEmail)
                            ->subject($this->translator->trans('emails.meeting_cancellation.subject'))
                            ->htmlTemplate('emails/meeting_cancellation.html.twig')
                            ->context([
                                'slot' => $slot,
                                'contactName' => $slot->getBookedByName() ?? $bookerEmail,
                            ])
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send meeting cancellation email', [
                        'slot' => $slot->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $this->addFlash('success', $this->translator->trans('meeting.flash.cancelled'));
        }
        
        return $this->redirectToRoute('meeting_index');
    }
    
    /**
     * Generate recurring slots
     */
    #[Route('/generate', name: 'meeting_generate', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function generate(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException('User not authenticated.');
            }
            $generateToken = $request->request->get('_csrf_token');
            if (!\is_string($generateToken) || !$this->isCsrfTokenValid('meeting_generate', $generateToken)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $data = $request->request->all();

            $titleParam = $data['title'] ?? null;
            $title = \is_string($titleParam) && $titleParam !== '' ? $titleParam : '30-min Meeting';
            $meetingTypeParam = $data['meetingType'] ?? null;
            $meetingType = \is_string($meetingTypeParam) && $meetingTypeParam !== '' ? $meetingTypeParam : MeetingSlot::TYPE_INTRODUCTION;
            $durationParam = $data['duration'] ?? 30;
            $duration = \is_numeric($durationParam) ? (int) $durationParam : 30;
            $weekdaysParam = $data['weekdays'] ?? [1, 2, 3, 4, 5];
            $weekdays = [1, 2, 3, 4, 5];
            if (\is_array($weekdaysParam)) {
                $weekdays = [];
                foreach ($weekdaysParam as $weekdayValue) {
                    $weekdays[] = is_bool($weekdayValue) ? (int) $weekdayValue : (\is_numeric($weekdayValue) ? (int) $weekdayValue : 0);
                }
            }
            $startTimeParam = $data['startTime'] ?? null;
            $startTimeOfDay = \is_string($startTimeParam) && $startTimeParam !== '' ? $startTimeParam : '09:00';
            $rangeStartParam = $data['rangeStart'] ?? null;
            $rangeStart = $this->parseDateParam(\is_string($rangeStartParam) ? $rangeStartParam : null, 'tomorrow');
            $rangeEndParam = $data['rangeEnd'] ?? null;
            $rangeEnd = $this->parseDateParam(\is_string($rangeEndParam) ? $rangeEndParam : null, '+14 days');
            if ($rangeStart === null || $rangeEnd === null) {
                $this->addFlash('error', 'Invalid date range.');
                return $this->redirectToRoute('meeting_generate');
            }
            if ($rangeEnd <= $rangeStart) {
                $this->addFlash('error', 'End date must be after start date.');
                return $this->redirectToRoute('meeting_generate');
            }
            $locationParam = $data['location'] ?? null;
            $location = \is_string($locationParam) ? $locationParam : null;
            $meetingUrlParam = $data['meetingUrl'] ?? null;
            $meetingUrl = \is_string($meetingUrlParam) ? $meetingUrlParam : null;
            $timezoneParam = $data['timezone'] ?? null;
            $timezone = \is_string($timezoneParam) && $timezoneParam !== '' ? $timezoneParam : 'UTC';

            $slots = $this->slotRepository->generateRecurringSlots(
                $user,
                $title,
                $meetingType,
                $duration,
                $weekdays,
                $startTimeOfDay,
                $rangeStart,
                $rangeEnd,
                $location,
                $meetingUrl,
                $timezone
            );
            
            $this->addFlash('success', $this->translator->trans('meeting.flash.generated', ['%count%' => count($slots)]));
            return $this->redirectToRoute('meeting_index');
        }
        
        return $this->render('meeting/generate.html.twig', [
            'meetingTypes' => MeetingSlot::getMeetingTypes(),
            'durations' => MeetingSlot::getDurations(),
        ]);
    }
    
    /**
     * Public booking page for a user (legacy route, now requires the signed
     * booking token to prevent slot enumeration by email guessing).
     * Prefer meeting_public_book_by_id (the link generated by meeting_my_link).
     */
    #[Route('/book/slot/{token}', name: 'meeting_book_slot', methods: ['GET', 'POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function bookSlot(string $token, Request $request): Response
    {
        $slot = $this->slotRepository->findByBookingToken($token);
        
        if (!$slot || !$slot->isAvailable()) {
            $this->addFlash('error', $this->translator->trans('meeting.flash.not_available'));
            return $this->redirectToRoute('app_dashboard');
        }
        
        $form = $this->createForm(MeetingBookingType::class);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, email: string, phone: string|null, company: string|null, notes: string|null} $data */
            $data = $form->getData();

            // ATOMIC booking claim: the available→booked transition happens
            // via a conditional UPDATE — two simultaneous submissions for the
            // last slot cannot both win (one gets affected-rows 0 and is
            // bounced). The isAvailable() check above is display-only.
            $claimed = $this->em->wrapInTransaction(function () use ($slot, $data): bool {
                $affected = $this->em->getConnection()->executeStatement(
                    "UPDATE meeting_slots SET status = 'booked', booked_by_name = :name, booked_by_email = :email, booked_at = NOW() WHERE id = :id AND status = 'available'",
                    [
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'id' => $slot->getId(),
                    ]
                );

                return $affected === 1; // loser of the race gets bounced
            });

            if ($claimed) {
                // Refresh the ORM entity from the claimed row for the
                // confirmation flow below (raw UPDATE bypassed the UoW).
                $this->em->clear();
                $refreshedSlot = $this->slotRepository->find($slot->getId());
                if ($refreshedSlot === null) {
                    throw $this->createNotFoundException('Meeting slot not found after booking.');
                }
                $slot = $refreshedSlot;
                $slot->book($data['name'], $data['email'], $data['phone'] ?? null, $data['company'] ?? null, $data['notes'] ?? null);
                $this->em->flush();
            }

            if (!$claimed) {
                $this->addFlash('error', $this->translator->trans('meeting.flash.not_available'));

                return $this->redirectToRoute('app_dashboard');
            }
            
            if (!$slot->isConfirmationSent()) {
                try {
                    $this->mailer->send(
                        (new TemplatedEmail())
                            ->from(new Address($this->mailerFromAddress, $this->mailerFromName))
                            ->to((string) $data['email'])
                            ->subject($this->translator->trans('emails.meeting_confirmation.subject'))
                            ->htmlTemplate('emails/meeting_confirmation.html.twig')
                            ->context([
                                'slot' => $slot,
                                'contactName' => $data['name'],
                            ])
                    );
                    $slot->setConfirmationSent(true);
                    $slot->setConfirmationSentAt(new DateTimeImmutable());
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send meeting confirmation email', [
                        'slot' => $slot->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return $this->render('meeting/booking_confirmed.html.twig', [
                'slot' => $slot,
            ]);
        }
        
        return $this->render('meeting/book_slot.html.twig', [
            'slot' => $slot,
            'form' => $form,
        ]);
    }
    
    /**
     * Cancel booking (public, via cancellation token)
     */

    #[Route('/book/{username}/{token}', name: 'meeting_public_book', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function publicBook(string $username, string $token, UserRepository $userRepository): Response
    {
        $owner = $userRepository->findOneBy(['email' => $username]);
        
        if (!$owner) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isValidBookingToken($owner->getId(), $owner->getEmail(), $token)) {
            throw $this->createNotFoundException('Invalid booking link.');
        }

        $availableSlots = $this->slotRepository->findAvailableByUser($owner);

        // Group slots by date
        $slotsByDate = [];
        /** @var MeetingSlot $slot */
        foreach ($availableSlots as $slot) {
            $dateKey = $slot->getStartTime()?->format('Y-m-d');
            if ($dateKey === null) {
                continue;
            }
            if (!isset($slotsByDate[$dateKey])) {
                $slotsByDate[$dateKey] = [];
            }
            $slotsByDate[$dateKey][] = $slot;
        }

        return $this->render('meeting/public_book.html.twig', [
            'owner' => $owner,
            'slotsByDate' => $slotsByDate,
        ]);
    }

    /**
     * Public booking page for a user (signed, share-safe link)
     */
    #[Route('/book/u/{id}/{token}', name: 'meeting_public_book_by_id', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function publicBookById(int $id, string $token, UserRepository $userRepository): Response
    {
        $owner = $userRepository->find($id);

        if (!$owner) {
            throw $this->createNotFoundException('User not found.');
        }

        if (!$this->isValidBookingToken($owner->getId(), $owner->getEmail(), $token)) {
            throw $this->createNotFoundException('Invalid booking link.');
        }

        $availableSlots = $this->slotRepository->findAvailableByUser($owner);

        // Group slots by date
        $slotsByDate = [];
        /** @var MeetingSlot $slot */
        foreach ($availableSlots as $slot) {
            $dateKey = $slot->getStartTime()?->format('Y-m-d');
            if ($dateKey === null) {
                continue;
            }
            if (!isset($slotsByDate[$dateKey])) {
                $slotsByDate[$dateKey] = [];
            }
            $slotsByDate[$dateKey][] = $slot;
        }

        return $this->render('meeting/public_book.html.twig', [
            'owner' => $owner,
            'slotsByDate' => $slotsByDate,
        ]);
    }
    
    /**
     * Book a specific slot (public)
     */

    #[Route('/cancel/{token}', name: 'meeting_public_cancel', methods: ['GET', 'POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function publicCancel(string $token, Request $request): Response
    {
        $slot = $this->slotRepository->findByCancellationToken($token);
        
        if (!$slot || !$slot->isBooked()) {
            $this->addFlash('error', $this->translator->trans('meeting.flash.invalid_cancellation'));
            return $this->redirectToRoute('app_dashboard');
        }
        
        if ($request->isMethod('POST')) {
            $cancelToken = $request->request->get('_token');
            if (!\is_string($cancelToken) || !$this->isCsrfTokenValid('meeting_public_cancel', $cancelToken)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $slot->makeAvailable();
            $this->em->flush();
            
            return $this->render('meeting/cancelled.html.twig', [
                'slot' => $slot,
            ]);
        }
        
        return $this->render('meeting/confirm_cancel.html.twig', [
            'slot' => $slot,
        ]);
    }
    
    /**
     * Get my booking link
     */
    #[Route('/my-link', name: 'meeting_my_link', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function myLink(): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException('User not authenticated.');
        }

        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException('Only CRM accounts have booking links.');
        }

        $bookingUrl = $this->generateUrl('meeting_public_book_by_id', [
            'id' => $user->getId(),
            'token' => $this->buildBookingToken($user->getId(), $user->getEmail()),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        
        return $this->render('meeting/my_link.html.twig', [
            'bookingUrl' => $bookingUrl,
        ]);
    }

    private function buildBookingToken(?int $userId, ?string $email): string
    {
        $idPart = $userId ?? 0;
        $emailPart = $email ?? '';
        // Use a dedicated signing key instead of kernel.secret for better security isolation
        $secretParam = $this->getParameter('app.booking_secret');
        $secret = \is_scalar($secretParam) ? (string) $secretParam : '';

        return hash_hmac('sha256', $idPart . '|' . strtolower($emailPart), $secret);
    }

    private function isValidBookingToken(?int $userId, ?string $email, string $token): bool
    {
        $expected = $this->buildBookingToken($userId, $email);
        return hash_equals($expected, $token);
    }
    
    private function getStatusColor(string $status): string
    {
        return match($status) {
            MeetingSlot::STATUS_AVAILABLE => '#28a745',
            MeetingSlot::STATUS_BOOKED => '#007bff',
            MeetingSlot::STATUS_BLOCKED => '#ffc107',
            MeetingSlot::STATUS_CANCELLED => '#dc3545',
            default => '#6c757d',
        };
    }

    /**
     * Parse a user-supplied date string into a DateTimeImmutable, or null when invalid.
     */
    private function parseDateParam(?string $value, string $default = 'now'): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            $value = $default;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
