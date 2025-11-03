<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Form\ContactType;
use App\Repository\ContactRepository;
use App\Service\LinkedInService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/contacts')]
class ContactController extends AbstractController
{
    public function __construct(
        private ContactRepository $contactRepository,
        private EntityManagerInterface $entityManager,
        private LinkedInService $linkedInService
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

        $qb->orderBy('c.lastName', 'ASC');

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
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($contact);
            $this->entityManager->flush();

            $this->addFlash('success', 'Contact created successfully!');

            return $this->redirectToRoute('app_contact_show', ['id' => $contact->getId()]);
        }

        return $this->render('contact/new.html.twig', [
            'contact' => $contact,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_contact_show', methods: ['GET'])]
    public function show(Contact $contact): Response
    {
        // Get LinkedIn engagement metrics if URL exists
        $linkedInMetrics = null;
        if ($contact->getLinkedInUrl()) {
            $linkedInMetrics = $this->linkedInService->getEngagementMetrics($contact);
        }

        return $this->render('contact/show.html.twig', [
            'contact' => $contact,
            'linkedInMetrics' => $linkedInMetrics,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_contact_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Contact $contact): Response
    {
        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Contact updated successfully!');

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

            $this->addFlash('success', 'Contact deleted successfully!');
        }

        return $this->redirectToRoute('app_contact_index');
    }

    #[Route('/{id}/linkedin-outreach', name: 'app_contact_linkedin_outreach', methods: ['POST'])]
    public function trackLinkedInOutreach(Request $request, Contact $contact): Response
    {
        $outreachType = $request->request->get('type', 'Connection Request');
        $notes = $request->request->get('notes', '');

        $this->linkedInService->trackLinkedInOutreach($contact, $outreachType, $notes);

        $this->addFlash('success', 'LinkedIn outreach tracked successfully!');

        return $this->redirectToRoute('app_contact_show', ['id' => $contact->getId()]);
    }
}
