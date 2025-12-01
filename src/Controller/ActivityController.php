<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Form\ActivityType;
use App\Repository\ActivityRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/activities')]
class ActivityController extends AbstractController
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private CompanyRepository $companyRepository,
        private ContactRepository $contactRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_activity_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $type = $request->query->get('type');
        $company = $request->query->get('company');
        $user = $request->query->get('user');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');

        // Build query
        $qb = $this->activityRepository->createQueryBuilder('a')
            ->leftJoin('a.company', 'c')
            ->leftJoin('a.contact', 'co')
            ->leftJoin('a.user', 'u')
            ->addSelect('c', 'co', 'u');

        if ($type) {
            $qb->andWhere('a.type = :type')
               ->setParameter('type', $type);
        }

        if ($company) {
            $qb->andWhere('c.id = :company')
               ->setParameter('company', $company);
        }

        if ($user) {
            $qb->andWhere('u.id = :user')
               ->setParameter('user', $user);
        }

        if ($dateFrom) {
            $qb->andWhere('a.activityDate >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('a.activityDate <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo));
        }

        $qb->orderBy('a.activityDate', 'DESC');

        $activities = $qb->getQuery()->getResult();

        // Get filter options
        $types = ['Call', 'Email', 'Meeting', 'LinkedIn Message', 'LinkedIn InMail', 'LinkedIn Connection Request', 'Follow-up'];
        
        $companies = $this->companyRepository->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $users = $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('activity/index.html.twig', [
            'activities' => $activities,
            'types' => $types,
            'companies' => $companies,
            'users' => $users,
            'current_type' => $type,
            'current_company' => $company,
            'current_user' => $user,
            'current_date_from' => $dateFrom,
            'current_date_to' => $dateTo,
        ]);
    }

    #[Route('/timeline', name: 'app_activity_timeline', methods: ['GET'])]
    public function timeline(Request $request): Response
    {
        $company = $request->query->get('company');
        $contact = $request->query->get('contact');

        $qb = $this->activityRepository->createQueryBuilder('a')
            ->leftJoin('a.company', 'c')
            ->leftJoin('a.contact', 'co')
            ->leftJoin('a.user', 'u')
            ->addSelect('c', 'co', 'u');

        if ($company) {
            $qb->andWhere('c.id = :company')
               ->setParameter('company', $company);
        }

        if ($contact) {
            $qb->andWhere('co.id = :contact')
               ->setParameter('contact', $contact);
        }

        $qb->orderBy('a.activityDate', 'DESC')
           ->setMaxResults(50);

        $activities = $qb->getQuery()->getResult();

        // Group by date
        $groupedActivities = [];
        foreach ($activities as $activity) {
            $date = $activity->getActivityDate()->format('Y-m-d');
            $groupedActivities[$date][] = $activity;
        }

        return $this->render('activity/timeline.html.twig', [
            'groupedActivities' => $groupedActivities,
        ]);
    }

    #[Route('/new', name: 'app_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $activity = new Activity();
        
        // Pre-fill company if provided
        if ($companyId = $request->query->get('company')) {
            $company = $this->companyRepository->find($companyId);
            if ($company) {
                $activity->setCompany($company);
            }
        }

        // Pre-fill contact if provided
        if ($contactId = $request->query->get('contact')) {
            $contact = $this->contactRepository->find($contactId);
            if ($contact) {
                $activity->setContact($contact);
            }
        }

        // Set current user
        $activity->setUser($this->getUser());
        $activity->setActivityDate(new \DateTime());

        $form = $this->createForm(ActivityType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($activity);
            $this->entityManager->flush();

            $this->addFlash('success', 'Activity logged successfully!');

            return $this->redirectToRoute('app_activity_index');
        }

        // Get all contacts with their company IDs for client-side filtering
        $contacts = $this->contactRepository->findAll();
        $contactsData = [];
        foreach ($contacts as $contact) {
            $contactsData[] = [
                'id' => $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'companyId' => $contact->getCompany() ? $contact->getCompany()->getId() : null,
            ];
        }

        return $this->render('activity/new.html.twig', [
            'activity' => $activity,
            'form' => $form,
            'contactsData' => json_encode($contactsData),
        ]);
    }

    #[Route('/{id}', name: 'app_activity_show', methods: ['GET'])]
    public function show(Activity $activity): Response
    {
        return $this->render('activity/show.html.twig', [
            'activity' => $activity,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_activity_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Activity $activity): Response
    {
        $form = $this->createForm(ActivityType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Activity updated successfully!');

            return $this->redirectToRoute('app_activity_show', ['id' => $activity->getId()]);
        }

        // Get all contacts with their company IDs for client-side filtering
        $contacts = $this->contactRepository->findAll();
        $contactsData = [];
        foreach ($contacts as $contact) {
            $contactsData[] = [
                'id' => $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'companyId' => $contact->getCompany() ? $contact->getCompany()->getId() : null,
            ];
        }

        return $this->render('activity/edit.html.twig', [
            'activity' => $activity,
            'form' => $form,
            'contactsData' => json_encode($contactsData),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_activity_delete', methods: ['POST'])]
    public function delete(Request $request, Activity $activity): Response
    {
        if ($this->isCsrfTokenValid('delete' . $activity->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($activity);
            $this->entityManager->flush();

            $this->addFlash('success', 'Activity deleted successfully!');
        }

        return $this->redirectToRoute('app_activity_index');
    }
}
