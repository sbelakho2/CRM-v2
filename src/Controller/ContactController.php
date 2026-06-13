<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Form\ContactType;
use App\Repository\ContactRepository;
use App\Service\ExportService;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/contacts')]
class ContactController extends AbstractController
{
    public function __construct(
        private ContactRepository $contactRepository,
        private EntityManagerInterface $entityManager,
        private ExportService $exportService,
        private GuidanceNotificationService $guidanceService,
        private TranslatorInterface $translator
    ) {}

    #[Route('', name: 'app_contact_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $role = $request->query->get('role');
        $company = $request->query->get('company');
        $search = $request->query->get('search');

        // Build query
        $qb = $this->contactRepository->createQueryBuilder('c')
            ->leftJoin('c.company', 'co')
            ->addSelect('co');

        if ($role) {
            $qb->andWhere('c.role = :role')
               ->setParameter('role', $role);
        }

        if ($company) {
            $qb->andWhere('co.id = :company')
               ->setParameter('company', $company);
        }

        if ($search) {
            $qb->andWhere('c.firstName LIKE :search OR c.lastName LIKE :search OR c.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.createdAt', 'DESC');

        $contacts = $qb->getQuery()->getResult();

        // Get filter options
        $roles = ['CEO', 'Procurement Manager', 'Engineering Manager', 'Quality Manager', 'Operations Manager', 'Supply Chain Manager'];
        
        // Get companies for dropdown
        $companies = $this->entityManager->getRepository(\App\Entity\Company::class)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('contact/index.html.twig', [
            'contacts' => $contacts,
            'roles' => $roles,
            'companies' => $companies,
            'current_role' => $role,
            'current_company' => $company,
            'current_search' => $search,
        ]);
    }

    #[Route('/new', name: 'app_contact_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $contact = new Contact();
        
        // Pre-select company if company_id is provided in query string
        $companyId = $request->query->get('company_id');
        if ($companyId) {
            $company = $this->entityManager->getRepository(\App\Entity\Company::class)->find($companyId);
            if ($company) {
                $contact->setCompany($company);
            }
        }
        
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            // Auto-dismiss "add contact" notification if it exists for this company
            if ($contact->getCompany()) {
                $companyId = $contact->getCompany()->getId();
                $this->guidanceService->autoDismissNotifications("company_{$companyId}_add_contact");
            }

            // Provide guidance for next steps
            $this->guidanceService->afterContactCreated($contact);

            $this->addFlash('success', $this->translator->trans('contact.flash.created'));

            // If contact was created from company page, redirect back to company
            if ($contact->getCompany()) {
                return $this->redirectToRoute('app_company_show', ['id' => $contact->getCompany()->getId()]);
            }

            return $this->redirectToRoute('app_contact_show', ['id' => $contact->getId()]);
        }

        return $this->render('contact/new.html.twig', [
            'contact' => $contact,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_contact_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Contact $contact): Response
    {
        return $this->render('contact/show.html.twig', [
            'contact' => $contact,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_contact_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Contact $contact): Response
    {
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('contact.flash.updated'));

            return $this->redirectToRoute('app_contact_show', ['id' => $contact->getId()]);
        }

        return $this->render('contact/edit.html.twig', [
            'contact' => $contact,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_contact_delete', methods: ['POST'])]
    public function delete(Request $request, Contact $contact): Response
    {
        if ($this->isCsrfTokenValid('delete' . $contact->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($contact);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('contact.flash.deleted'));
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/export/{format}', name: 'app_contact_export', requirements: ['format' => 'csv|xlsx'], methods: ['GET'])]
    public function export(Request $request, string $format): Response
    {
        // Get the same filters as index action
        $role = $request->query->get('role');
        $company = $request->query->get('company');
        $search = $request->query->get('search');

        $qb = $this->contactRepository->createQueryBuilder('c')
            ->leftJoin('c.company', 'co')
            ->addSelect('co');

        if ($role) {
            $qb->andWhere('c.role = :role')
               ->setParameter('role', $role);
        }

        if ($company) {
            $qb->andWhere('co.id = :company')
               ->setParameter('company', $company);
        }

        if ($search) {
            $qb->andWhere('c.firstName LIKE :search OR c.lastName LIKE :search OR c.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $qb->orderBy('c.createdAt', 'DESC');

        $contacts = $qb->getQuery()->getResult();

        // Generate export file
        $filepath = $this->exportService->exportContacts($contacts, $format);

        // Create response
        $response = new BinaryFileResponse($filepath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            basename($filepath)
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
