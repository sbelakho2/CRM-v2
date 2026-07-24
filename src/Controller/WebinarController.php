<?php

namespace App\Controller;

use App\Entity\Webinar;
use App\Entity\WebinarAttendee;
use App\Entity\Contact;
use App\Form\WebinarType;
use App\Repository\WebinarRepository;
use App\Repository\ContactRepository;
use App\Service\WebinarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/webinars')]
#[IsGranted('ROLE_USER')]
class WebinarController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WebinarRepository $webinarRepository,
        private ContactRepository $contactRepository,
        private WebinarService $webinarService
    ) {}

    #[Route('', name: 'app_webinar_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status', 'all');
        
        $queryBuilder = $this->webinarRepository->createQueryBuilder('w')
            ->orderBy('w.scheduledDate', 'DESC');

        if ($status === 'upcoming') {
            $queryBuilder->where('w.scheduledDate > :now')
                ->setParameter('now', new \DateTime());
        } elseif ($status === 'past') {
            $queryBuilder->where('w.scheduledDate <= :now')
                ->setParameter('now', new \DateTime());
        }

        $webinars = $queryBuilder->getQuery()->getResult();

        return $this->render('webinar/index.html.twig', [
            'webinars' => $webinars,
            'current_status' => $status,
        ]);
    }

    #[Route('/new', name: 'app_webinar_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $webinar = new Webinar();
        $form = $this->createForm(WebinarType::class, $webinar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($webinar);
            $this->entityManager->flush();

            $this->addFlash('success', 'Webinar created successfully!');
            return $this->redirectToRoute('app_webinar_show', ['id' => $webinar->getId()]);
        }

        return $this->render('webinar/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_webinar_show', methods: ['GET'])]
    public function show(Webinar $webinar): Response
    {
        $attendees = $this->entityManager->getRepository(WebinarAttendee::class)
            ->findBy(['webinar' => $webinar], ['registeredAt' => 'DESC']);

        $stats = [
            'total_registered' => count($attendees),
            'attended' => count(array_filter($attendees, fn($a) => $a->isAttended())),
            'attendance_rate' => count($attendees) > 0 
                ? round((count(array_filter($attendees, fn($a) => $a->isAttended())) / count($attendees)) * 100, 1)
                : 0,
        ];

        return $this->render('webinar/show.html.twig', [
            'webinar' => $webinar,
            'attendees' => $attendees,
            'stats' => $stats,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_webinar_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Webinar $webinar): Response
    {
        $form = $this->createForm(WebinarType::class, $webinar);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Webinar updated successfully!');
            return $this->redirectToRoute('app_webinar_show', ['id' => $webinar->getId()]);
        }

        return $this->render('webinar/edit.html.twig', [
            'webinar' => $webinar,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_webinar_delete', methods: ['POST'])]
    public function delete(Request $request, Webinar $webinar): Response
    {
        if ($this->isCsrfTokenValid('delete'.$webinar->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($webinar);
            $this->entityManager->flush();

            $this->addFlash('success', 'Webinar deleted successfully!');
        }

        return $this->redirectToRoute('app_webinar_index');
    }

    #[Route('/{id}/register', name: 'app_webinar_register', methods: ['GET', 'POST'])]
    public function register(Request $request, Webinar $webinar): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $firstName = $request->request->get('first_name');
            $lastName = $request->request->get('last_name');
            $company = $request->request->get('company');

            // Find or create contact
            $contact = $this->contactRepository->findOneBy(['email' => $email]);
            
            if (!$contact) {
                $contact = new Contact();
                $contact->setEmail($email);
                $contact->setFirstName($firstName);
                $contact->setLastName($lastName);
                // Note: Company relationship would need to be handled if required
                $this->entityManager->persist($contact);
                $this->entityManager->flush();
            }

            // Register attendee
            $this->webinarService->registerAttendee(
                $webinar, 
                $contact, 
                $email, 
                trim($firstName . ' ' . $lastName)
            );

            $this->addFlash('success', 'Registration successful! Check your email for confirmation.');
            return $this->redirectToRoute('app_webinar_register_confirmation', ['id' => $webinar->getId()]);
        }

        return $this->render('webinar/register.html.twig', [
            'webinar' => $webinar,
        ]);
    }

    #[Route('/{id}/register/confirmation', name: 'app_webinar_register_confirmation', methods: ['GET'])]
    public function registerConfirmation(Webinar $webinar): Response
    {
        return $this->render('webinar/confirmation.html.twig', [
            'webinar' => $webinar,
        ]);
    }



    #[Route('/{id}/attendees/{attendeeId}/mark-attended', name: 'app_webinar_mark_attended', methods: ['POST'])]
    public function markAttended(Webinar $webinar, int $attendeeId): Response
    {
        $attendee = $this->entityManager->getRepository(WebinarAttendee::class)->find($attendeeId);

        if ($attendee && $attendee->getWebinar()->getId() === $webinar->getId()) {
            $this->webinarService->markAttended($attendee);
            $this->addFlash('success', 'Attendee marked as attended.');
        }

        return $this->redirectToRoute('app_webinar_show', ['id' => $webinar->getId()]);
    }

    #[Route('/{id}/send-followup', name: 'app_webinar_send_followup', methods: ['POST'])]
    public function sendFollowUp(Request $request, Webinar $webinar): Response
    {
        if ($this->isCsrfTokenValid('followup'.$webinar->getId(), $request->request->get('_token'))) {
            $attendees = $this->webinarService->getAttendeesNeedingFollowUp($webinar);
            
            $sentCount = 0;
            foreach ($attendees as $attendee) {
                try {
                    $this->webinarService->sendFollowUpEmail($attendee);
                    $sentCount++;
                } catch (\Exception $e) {
                    // Log error but continue with other attendees
                    $this->addFlash('warning', 'Failed to send email to ' . $attendee->getEmail());
                }
            }
            
            if ($sentCount > 0) {
                $this->addFlash('success', $sentCount . ' follow-up email(s) sent successfully!');
            } else {
                $this->addFlash('info', 'No attendees need follow-up emails.');
            }
        }

        return $this->redirectToRoute('app_webinar_show', ['id' => $webinar->getId()]);
    }
}
