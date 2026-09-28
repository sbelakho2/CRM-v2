<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\Webinar;
use App\Repository\ContactRepository;
use App\Service\WebinarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * PUBLIC webinar registration endpoints.
 *
 * Split from the ROLE_USER-protected WebinarController: the registration
 * link is handed to external recipients, so these routes must be reachable
 * without a CRM account (firewall entries in security.yaml). External
 * registrants are NOT forced into the CRM contact model — a Contact is only
 * linked when the email already matches one.
 */
#[Route('/webinars')]
#[IsGranted('PUBLIC_ACCESS')]
class PublicWebinarController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactRepository $contactRepository,
        private WebinarService $webinarService,
        private TranslatorInterface $translator,
    ) {}

    #[Route('/{id}/register', name: 'app_webinar_register', methods: ['GET', 'POST'])]
    public function register(Request $request, Webinar $webinar): Response
    {
        // Archived webinars stop accepting registrations but keep their
        // history; the public link simply stops resolving.
        if ($webinar->isArchived()) {
            throw new NotFoundHttpException('This webinar is no longer available.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('webinar_register_' . $webinar->getId(), (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = strtolower(trim((string) $request->request->get('email')));
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($firstName === '' && $lastName === '')) {
                $this->addFlash('error', 'Please provide a valid email address and your name.');

                return $this->render('webinar/register.html.twig', ['webinar' => $webinar]);
            }

            // Link an existing CRM contact when one matches; never create
            // Contact rows from unreviewed public submissions (Contact
            // requires a Company — see WebinarService::registerAttendee).
            $contact = $this->contactRepository->findOneBy(['email' => $email]);

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
}
