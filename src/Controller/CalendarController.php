<?php

namespace App\Controller;

use App\Entity\CalendarEvent;
use App\Form\CalendarEventType;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/calendar')]
#[IsGranted('ROLE_USER')]
class CalendarController extends AbstractController
{
    public function __construct(
        private readonly CalendarEventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = $request->query->get('view', 'month');
        $dateStr = $request->query->get('date', 'now');
        
        try {
            $currentDate = new \DateTime($dateStr);
        } catch (\Exception) {
            $currentDate = new \DateTime();
        }

        // Get upcoming events for sidebar
        $upcomingEvents = $this->eventRepository->findUpcoming($this->getUser(), 5);
        
        // Get today's events
        $todayEvents = $this->eventRepository->findToday($this->getUser());
        
        // Get events happening now
        $happeningNow = $this->eventRepository->findHappeningNow($this->getUser());

        return $this->render('calendar/index.html.twig', [
            'view' => $view,
            'currentDate' => $currentDate,
            'upcomingEvents' => $upcomingEvents,
            'todayEvents' => $todayEvents,
            'happeningNow' => $happeningNow,
            'eventTypes' => CalendarEvent::getTypes(),
        ]);
    }

    #[Route('/events', name: 'calendar_events_json', methods: ['GET'])]
    public function getEventsJson(Request $request): JsonResponse
    {
        $start = new \DateTime($request->query->get('start', 'first day of this month'));
        $end = new \DateTime($request->query->get('end', 'last day of this month'));

        $events = $this->eventRepository->findForFullCalendar(
            $start,
            $end,
            $this->getUser()
        );

        $payload = array_map(static function (array $event): array {
            $eventData = [
                'id' => $event['id'],
                'title' => $event['title'] ?? '',
                'start' => $event['start'] ?? null,
                'allDay' => $event['allDay'] ?? false,
                'url' => null,
                'extendedProps' => [
                    'description' => $event['description'] ?? null,
                    'location' => $event['location'] ?? null,
                ],
            ];

            if (!empty($event['end'])) {
                $eventData['end'] = $event['end'];
            }

            if (!empty($event['color'])) {
                $eventData['backgroundColor'] = $event['color'];
            }
            if (!empty($event['borderColor'])) {
                $eventData['borderColor'] = $event['borderColor'];
            }
            if (!empty($event['textColor'])) {
                $eventData['textColor'] = $event['textColor'];
            }

            return $eventData;
        }, $events);

        return $this->json($payload);
    }

    #[Route('/new', name: 'calendar_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $event = new CalendarEvent();
        $event->setOrganizer($this->getUser());
        
        // Pre-fill from query params
        if ($request->query->has('start')) {
            $event->setStartAt(new \DateTime($request->query->get('start')));
            $event->setEndAt((clone $event->getStartAt())->modify('+1 hour'));
        } else {
            $event->setStartAt(new \DateTime());
            $event->setEndAt((new \DateTime())->modify('+1 hour'));
        }
        
        if ($request->query->has('type')) {
            $event->setEventType($request->query->get('type'));
        }

        $form = $this->createForm(CalendarEventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check for conflicts
            $conflicts = $this->eventRepository->findConflicts(
                $event->getStartAt(),
                $event->getEndAt(),
                $this->getUser()
            );
            
            if (!empty($conflicts) && !$request->request->getBoolean('ignore_conflicts')) {
                $this->addFlash('warning', $this->translator->trans('calendar.flash.overlap', ['%count%' => count($conflicts)]));
                $request->request->set('ignore_conflicts', true);
            } else {
                $this->entityManager->persist($event);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator->trans('calendar.flash.created'));

                if ($request->isXmlHttpRequest()) {
                    return $this->json([
                        'success' => true,
                        'event' => $event->toFullCalendarEvent(),
                    ]);
                }

                return $this->redirectToRoute('calendar_index');
            }
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('calendar/_form_modal.html.twig', [
                'form' => $form,
                'event' => $event,
            ]);
        }

        return $this->render('calendar/new.html.twig', [
            'form' => $form,
            'event' => $event,
        ]);
    }

    #[Route('/{id}', name: 'calendar_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(CalendarEvent $event): Response
    {
        return $this->render('calendar/show.html.twig', [
            'event' => $event,
        ]);
    }

    #[Route('/{id}/edit', name: 'calendar_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CalendarEvent $event): Response
    {
        // Check permission
        if ($event->getOrganizer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only edit your own events.');
        }

        $form = $this->createForm(CalendarEventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('calendar.flash.updated'));

            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => true,
                    'event' => $event->toFullCalendarEvent(),
                ]);
            }

            return $this->redirectToRoute('calendar_show', ['id' => $event->getId()]);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->render('calendar/_form_modal.html.twig', [
                'form' => $form,
                'event' => $event,
            ]);
        }

        return $this->render('calendar/edit.html.twig', [
            'form' => $form,
            'event' => $event,
        ]);
    }

    #[Route('/{id}/delete', name: 'calendar_delete', methods: ['POST'])]
    public function delete(Request $request, CalendarEvent $event): Response
    {
        // Check permission
        if ($event->getOrganizer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only delete your own events.');
        }

        if ($this->isCsrfTokenValid('delete' . $event->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($event);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('calendar.flash.deleted'));
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true]);
        }

        return $this->redirectToRoute('calendar_index');
    }

    #[Route('/{id}/cancel', name: 'calendar_cancel', methods: ['POST'])]
    public function cancel(Request $request, CalendarEvent $event): Response
    {
        if ($event->getOrganizer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('You can only cancel your own events.');
        }

        if ($this->isCsrfTokenValid('cancel' . $event->getId(), $request->request->get('_token'))) {
            $event->setStatus(CalendarEvent::STATUS_CANCELLED);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('calendar.flash.cancelled'));
        }

        return $this->redirectToRoute('calendar_show', ['id' => $event->getId()]);
    }

    #[Route('/api/update', name: 'calendar_api_update', methods: ['POST'])]
    public function apiUpdate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('calendar_update', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        
        if (!isset($data['id'])) {
            return $this->json(['error' => 'Event ID required'], 400);
        }

        $event = $this->eventRepository->find($data['id']);
        
        if (!$event) {
            return $this->json(['error' => 'Event not found'], 404);
        }

        if ($event->getOrganizer() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Permission denied'], 403);
        }

        // Update from drag/drop or resize
        if (isset($data['start'])) {
            $event->setStartAt(new \DateTime($data['start']));
        }
        
        if (isset($data['end'])) {
            $event->setEndAt(new \DateTime($data['end']));
        }
        
        if (isset($data['allDay'])) {
            $event->setAllDay($data['allDay']);
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'event' => $event->toFullCalendarEvent(),
        ]);
    }

    #[Route('/api/quick-add', name: 'calendar_quick_add', methods: ['POST'])]
    public function quickAdd(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('calendar_quick_add', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        
        if (empty($data['title']) || empty($data['start'])) {
            return $this->json(['error' => 'Title and start time required'], 400);
        }

        $event = new CalendarEvent();
        $event->setTitle($data['title']);
        $event->setOrganizer($this->getUser());
        $event->setStartAt(new \DateTime($data['start']));
        
        if (!empty($data['end'])) {
            $event->setEndAt(new \DateTime($data['end']));
        } else {
            $event->setEndAt((clone $event->getStartAt())->modify('+1 hour'));
        }
        
        if (!empty($data['allDay'])) {
            $event->setAllDay(true);
        }
        
        if (!empty($data['type'])) {
            $event->setEventType($data['type']);
        }

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'event' => $event->toFullCalendarEvent(),
        ]);
    }

    #[Route('/day/{date}', name: 'calendar_day', methods: ['GET'])]
    public function dayView(string $date): Response
    {
        try {
            $currentDate = new \DateTime($date);
        } catch (\Exception) {
            $currentDate = new \DateTime();
        }

        $events = $this->eventRepository->findByDate($currentDate, $this->getUser());

        // Sort by time
        usort($events, fn($a, $b) => $a->getStartAt() <=> $b->getStartAt());

        return $this->render('calendar/day.html.twig', [
            'currentDate' => $currentDate,
            'events' => $events,
            'prevDate' => (clone $currentDate)->modify('-1 day'),
            'nextDate' => (clone $currentDate)->modify('+1 day'),
        ]);
    }

    #[Route('/week/{date}', name: 'calendar_week', methods: ['GET'])]
    public function weekView(?string $date = null): Response
    {
        try {
            $currentDate = $date ? new \DateTime($date) : new \DateTime();
        } catch (\Exception) {
            $currentDate = new \DateTime();
        }

        $events = $this->eventRepository->findByWeek($currentDate, $this->getUser());

        // Group events by day
        $eventsByDay = [];
        foreach ($events as $event) {
            $day = $event->getStartAt()->format('Y-m-d');
            if (!isset($eventsByDay[$day])) {
                $eventsByDay[$day] = [];
            }
            $eventsByDay[$day][] = $event;
        }

        // Calculate week dates
        $dayOfWeek = (int) $currentDate->format('N');
        $weekStart = (clone $currentDate)->modify('-' . ($dayOfWeek - 1) . ' days');
        $weekEnd = (clone $weekStart)->modify('+6 days');

        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $day = (clone $weekStart)->modify("+$i days");
            $weekDays[] = $day;
        }

        return $this->render('calendar/week.html.twig', [
            'currentDate' => $currentDate,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'weekDays' => $weekDays,
            'eventsByDay' => $eventsByDay,
            'prevWeek' => (clone $weekStart)->modify('-7 days'),
            'nextWeek' => (clone $weekStart)->modify('+7 days'),
        ]);
    }

    #[Route('/availability/{date}', name: 'calendar_availability', methods: ['GET'])]
    public function availability(Request $request, string $date): JsonResponse
    {
        try {
            $checkDate = new \DateTime($date);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date'], 400);
        }

        $busySlots = $this->eventRepository->getUserAvailability($this->getUser(), $checkDate);

        return $this->json([
            'date' => $checkDate->format('Y-m-d'),
            'busySlots' => $busySlots,
        ]);
    }

    #[Route('/search', name: 'calendar_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = $request->query->get('q', '');
        $events = [];

        if (strlen($query) >= 2) {
            $events = $this->eventRepository->search($query, $this->getUser());
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(array_map(fn($e) => [
                'id' => $e->getId(),
                'title' => $e->getTitle(),
                'start' => $e->getStartAt()->format('Y-m-d H:i'),
                'type' => $e->getEventType(),
            ], $events));
        }

        return $this->render('calendar/search.html.twig', [
            'query' => $query,
            'events' => $events,
        ]);
    }
}
