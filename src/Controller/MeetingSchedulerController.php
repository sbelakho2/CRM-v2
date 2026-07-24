<?php

namespace App\Controller;

use App\Entity\MeetingSlot;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

#[Route('/meetings')]
#[IsGranted('ROLE_USER')]
class MeetingSchedulerController extends AbstractController
{
    public function __construct(
        private MeetingSlotRepository $slotRepository,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator
    ) {}
    
    /**
     * My meetings dashboard (authenticated)
     */
    #[Route('', name: 'meeting_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $user = $this->getUser();
        
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
        $start = new DateTimeImmutable($request->query->get('start', 'now'));
        $end = new DateTimeImmutable($request->query->get('end', '+30 days'));
        
        $slots = $this->slotRepository->findByUserAndDateRange($user, $start, $end);
        
        $events = [];
        foreach ($slots as $slot) {
            $events[] = [
                'id' => $slot->getId(),
                'title' => $slot->getTitle(),
                'start' => $slot->getStartTime()->format('c'),
                'end' => $slot->getEndTime()->format('c'),
                'backgroundColor' => $this->getStatusColor($slot->getStatus()),
                'borderColor' => $this->getStatusColor($slot->getStatus()),
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
        $slot->setOwner($this->getUser());
        
        // Pre-fill from query params if provided
        if ($startTime = $request->query->get('start')) {
            $slot->setStartTime(new DateTimeImmutable($startTime));
        }
        
        $form = $this->createForm(MeetingSlotType::class, $slot);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            // Calculate end time from duration
            $endTime = $slot->getStartTime()->modify("+{$slot->getDurationMinutes()} minutes");
            $slot->setEndTime($endTime);
            
            // Check for conflicts
            $conflicts = $this->slotRepository->findConflicts(
                $this->getUser(),
                $slot->getStartTime(),
                $slot->getEndTime()
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
            $endTime = $slot->getStartTime()->modify("+{$slot->getDurationMinutes()} minutes");
            $slot->setEndTime($endTime);
            
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
        
        if ($this->isCsrfTokenValid('delete' . $slot->getId(), $request->request->get('_token'))) {
            $this->slotRepository->remove($slot, true);
            $this->addFlash('success', $this->translator->trans('meeting.flash.deleted'));
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
        
        if ($this->isCsrfTokenValid('cancel' . $slot->getId(), $request->request->get('_token'))) {
            $slot->cancel();
            $this->em->flush();
            
            // TODO: Send cancellation email to booker
            
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
            $data = $request->request->all();
            
            $title = $data['title'] ?? '30-min Meeting';
            $meetingType = $data['meetingType'] ?? MeetingSlot::TYPE_INTRODUCTION;
            $duration = (int) ($data['duration'] ?? 30);
            $weekdays = array_map('intval', $data['weekdays'] ?? [1, 2, 3, 4, 5]);
            $startTime = $data['startTime'] ?? '09:00';
            $rangeStart = new DateTimeImmutable($data['rangeStart'] ?? 'tomorrow');
            $rangeEnd = new DateTimeImmutable($data['rangeEnd'] ?? '+14 days');
            $location = $data['location'] ?? null;
            $meetingUrl = $data['meetingUrl'] ?? null;
            $timezone = $data['timezone'] ?? 'UTC';
            
            $slots = $this->slotRepository->generateRecurringSlots(
                $this->getUser(),
                $title,
                $meetingType,
                $duration,
                $weekdays,
                $startTime,
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
     * Public booking page for a user
     */
    #[Route('/book/{username}', name: 'meeting_public_book', methods: ['GET'])]
    public function publicBook(string $username, UserRepository $userRepository): Response
    {
        $owner = $userRepository->findOneBy(['email' => $username]);
        
        if (!$owner) {
            throw $this->createNotFoundException('User not found.');
        }
        
        $availableSlots = $this->slotRepository->findAvailableByUser($owner);
        
        // Group slots by date
        $slotsByDate = [];
        foreach ($availableSlots as $slot) {
            $dateKey = $slot->getStartTime()->format('Y-m-d');
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
        foreach ($availableSlots as $slot) {
            $dateKey = $slot->getStartTime()->format('Y-m-d');
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
    #[Route('/book/slot/{token}', name: 'meeting_book_slot', methods: ['GET', 'POST'])]
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
            $data = $form->getData();
            
            $slot->book(
                $data['name'],
                $data['email'],
                $data['phone'] ?? null,
                $data['company'] ?? null,
                $data['notes'] ?? null
            );
            
            $this->em->flush();
            
            // TODO: Send confirmation emails
            
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
    #[Route('/cancel/{token}', name: 'meeting_public_cancel', methods: ['GET', 'POST'])]
    public function publicCancel(string $token, Request $request): Response
    {
        $slot = $this->slotRepository->findByCancellationToken($token);
        
        if (!$slot || !$slot->isBooked()) {
            $this->addFlash('error', $this->translator->trans('meeting.flash.invalid_cancellation'));
            return $this->redirectToRoute('app_dashboard');
        }
        
        if ($request->isMethod('POST')) {
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
        $secret = (string) $this->getParameter('app.booking_secret');

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
}
