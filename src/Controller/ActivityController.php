<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\User;
use App\Form\ActivityType;
use App\Repository\ActivityRepository;
use App\Repository\CompanyRepository;
use App\Repository\ContactRepository;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/activities')]
#[IsGranted('ROLE_USER')]
class ActivityController extends AbstractController
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private CompanyRepository $companyRepository,
        private ContactRepository $contactRepository,
        private EntityManagerInterface $entityManager,
        private GuidanceNotificationService $guidanceService
    ) {}

    /**
     * AJAX endpoint for contact autocomplete search
     * Prevents OOM by loading only matching contacts instead of the entire database
     */
    #[Route('/api/contacts/search', name: 'app_activity_contacts_search', methods: ['GET'])]
    public function searchContacts(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        
        $query = trim($request->query->get('q', ''));
        /** @var string|int|float|bool|null $companyId */
        $companyId = $request->query->get('company');
        $limit = min(50, max(1, (int) $request->query->get('limit', 25)));
        
        $qb = $this->contactRepository->createQueryBuilder('c')
            ->leftJoin('c.company', 'co')
            ->select('c.id', 'c.firstName', 'c.lastName', 'c.email', 'IDENTITY(c.company) as companyId', 'co.name as companyName')
            ->setMaxResults($limit);
        
        // Filter by company if provided
        if ($companyId) {
            $qb->andWhere('c.company = :companyId')
               ->setParameter('companyId', $companyId);
        }
        
        // Search by name or email if query provided
        if (strlen($query) >= 2) {
            $qb->andWhere('c.firstName LIKE :query OR c.lastName LIKE :query OR c.email LIKE :query')
               ->setParameter('query', '%' . $query . '%');
        }
        
        $qb->orderBy('c.lastName', 'ASC')
           ->addOrderBy('c.firstName', 'ASC');
        
        /** @var list<array{id: mixed, firstName: mixed, lastName: mixed, email: mixed, companyId: mixed, companyName: mixed}> $results */
        $results = $qb->getQuery()->getResult();

        $contacts = [];
        foreach ($results as $row) {
            $firstName = is_string($row['firstName']) ? $row['firstName'] : '';
            $lastName = is_string($row['lastName']) ? $row['lastName'] : '';
            $companyName = is_string($row['companyName']) ? $row['companyName'] : '';
            $contacts[] = [
                'id' => $row['id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'companyId' => $row['companyId'],
                'companyName' => $row['companyName'],
                'label' => trim($firstName . ' ' . $lastName) . ($companyName !== '' ? ' (' . $companyName . ')' : ''),
            ];
        }
        
        return new JsonResponse($contacts);
    }

    #[Route('', name: 'app_activity_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Get filter parameters
        /** @var string|int|float|bool|null $type */
        $type = $request->query->get('type');
        /** @var string|int|float|bool|null $company */
        $company = $request->query->get('company');
        /** @var string|int|float|bool|null $user */
        $user = $request->query->get('user');
        /** @var string|null $dateFrom */
        $dateFrom = $request->query->get('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->query->get('date_to');

        // Build query
        $qb = $this->activityRepository->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
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
            $dateFromParsed = $this->parseDateParam($dateFrom);
            if ($dateFromParsed === null) {
                $this->addFlash('error', 'Invalid date_from filter. Please use YYYY-MM-DD format.');
                return $this->redirectToRoute('app_activity_index');
            }
            $qb->andWhere('a.activityDate >= :dateFrom')
               ->setParameter('dateFrom', $dateFromParsed);
        }

        if ($dateTo) {
            $dateToParsed = $this->parseDateParam($dateTo);
            if ($dateToParsed === null) {
                $this->addFlash('error', 'Invalid date_to filter. Please use YYYY-MM-DD format.');
                return $this->redirectToRoute('app_activity_index');
            }
            $qb->andWhere('a.activityDate <= :dateTo')
               ->setParameter('dateTo', $dateToParsed);
        }

        $qb->orderBy('a.activityDate', 'DESC');

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;

        // Total matching rows (before the limit) so the template can render
        // pagination — with only page 1 reachable, older activities were
        // effectively hidden once a month's worth filled the first page.
        $countQb = (clone $qb)->select('COUNT(DISTINCT a.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $limit));

        $qb->setMaxResults($limit)
           ->setFirstResult(($page - 1) * $limit);

        $activities = $qb->getQuery()->getResult();

        // Get filter options
        $types = [
            'Call', 'Email', 'Meeting', 'Follow-up', 'Site Visit', 'Demo', 
            'Proposal', 'Negotiation', 'Task', 'Note', 
            'LinkedIn Message', 'LinkedIn Connection Request', 'LinkedIn InMail', 'Other'
        ];
        
        /** @var list<\App\Entity\Company> $companies */
        $companies = $this->companyRepository->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        if ($company !== null && !in_array($company, array_map(static fn($c) => $c->getId(), $companies), true)) {
            $selectedCompany = $this->companyRepository->find($company);
            if ($selectedCompany) {
                $companies[] = $selectedCompany;
            }
        }

        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(\App\Entity\User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.lastName', 'ASC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        if ($user !== null && !in_array($user, array_map(static fn($u) => $u->getId(), $users), true)) {
            $selectedUser = $this->entityManager->getRepository(\App\Entity\User::class)->find($user);
            if ($selectedUser) {
                $users[] = $selectedUser;
            }
        }

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
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'limit' => $limit,
        ]);
    }

    #[Route('/timeline', name: 'app_activity_timeline', methods: ['GET'])]
    public function timeline(Request $request): Response
    {
        /** @var string|int|float|bool|null $company */
        $company = $request->query->get('company');
        /** @var string|int|float|bool|null $contact */
        $contact = $request->query->get('contact');

        $qb = $this->activityRepository->createQueryBuilder('a')
            ->andWhere('a.archivedAt IS NULL')
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

        /** @var list<Activity> $activities */
        $activities = $qb->getQuery()->getResult();

        // Group by date
        $groupedActivities = [];
        foreach ($activities as $activity) {
            $activityDate = $activity->getActivityDate();
            $date = $activityDate !== null ? $activityDate->format('Y-m-d') : 'undated';
            $groupedActivities[$date][] = $activity;
        }

        return $this->render('activity/timeline.html.twig', [
            'groupedActivities' => $groupedActivities,
        ]);
    }

    #[Route('/new', name: 'app_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

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
        /** @var User|null $currentUser - ROLE_USER is enforced at class level. */
        $currentUser = $this->getUser();
        $activity->setUser($currentUser);
        $activity->setActivityDate(new \DateTime());

        $form = $this->createForm(ActivityType::class, $activity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($activity);
            $this->entityManager->flush();

            // Auto-dismiss "log activity" notification if it exists for this company/contact
            if ($activity->getCompany()) {
                $companyId = $activity->getCompany()->getId();
                $this->guidanceService->autoDismissNotifications("company_{$companyId}_log_activity");
            }
            if ($activity->getContact()) {
                $contactId = $activity->getContact()->getId();
                $this->guidanceService->autoDismissNotifications("contact_{$contactId}_log_activity");
            }

            // Provide guidance based on activity outcome
            $this->guidanceService->afterActivityLogged($activity);

            $this->addFlash('success', 'Activity logged successfully!');

            $allowedTargets = ['app_activity_index', 'app_company_show', 'app_contact_show'];
            $targetRoute = $request->query->get('redirect', '');
            $targetRoute = in_array($targetRoute, $allowedTargets, true) ? $targetRoute : 'app_activity_index';
            $targetParams = [];
            
            /** @var string|int|float|bool|null $redirectParams */
            $redirectParams = $request->query->get('redirect_params');
            if (is_string($redirectParams) && $redirectParams !== '') {
                $decoded = json_decode($redirectParams, true);
                $targetParams = is_array($decoded) ? $decoded : [];
            } elseif ($targetRoute === 'app_company_show' && $activity->getCompany()) {
                $targetParams = ['id' => $activity->getCompany()->getId()];
            } elseif ($targetRoute === 'app_contact_show' && $activity->getContact()) {
                $targetParams = ['id' => $activity->getContact()->getId()];
            }

            try {
                return $this->redirectToRoute($targetRoute, $targetParams);
            } catch (\Exception $e) {
                return $this->redirectToRoute('app_activity_index');
            }
        }

        // For pre-selected contact, get just that contact's data for initial display
        // The AJAX endpoint will handle searching for additional contacts
        $initialContactData = null;
        if ($activity->getContact()) {
            $contact = $activity->getContact();
            $initialContactData = [
                'id' => $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
                'companyId' => $contact->getCompany() ? $contact->getCompany()->getId() : null,
                'companyName' => $contact->getCompany() ? $contact->getCompany()->getName() : null,
            ];
        }

        return $this->render('activity/new.html.twig', [
            'activity' => $activity,
            'form' => $form,
            'initialContactData' => $initialContactData ? json_encode($initialContactData) : 'null',
            'contactSearchUrl' => $this->generateUrl('app_activity_contacts_search'),
        ]);
    }

    #[Route('/{id}', name: 'app_activity_show', requirements: ['id' => '\d+'], methods: ['GET'])]
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

        // For the current contact, get just that contact's data for initial display
        // The AJAX endpoint will handle searching for additional contacts
        $initialContactData = null;
        if ($activity->getContact()) {
            $contact = $activity->getContact();
            $initialContactData = [
                'id' => $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
                'companyId' => $contact->getCompany() ? $contact->getCompany()->getId() : null,
                'companyName' => $contact->getCompany() ? $contact->getCompany()->getName() : null,
            ];
        }

        return $this->render('activity/edit.html.twig', [
            'activity' => $activity,
            'form' => $form,
            'initialContactData' => $initialContactData ? json_encode($initialContactData) : 'null',
            'contactSearchUrl' => $this->generateUrl('app_activity_contacts_search'),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_activity_delete', methods: ['POST'])]
    public function delete(Request $request, Activity $activity): Response
    {
        $token = $request->request->get('_token');
        /** @var User|null $user - ROLE_USER is enforced at class level. */
        $user = $this->getUser();
        if ($user instanceof User && $this->isCsrfTokenValid('delete' . $activity->getId(), is_string($token) ? $token : null)) {
            // Activities are historical sales records: archive them.
            $activity->archive($user, 'Archived from activities list');
            $this->entityManager->flush();

            $this->addFlash('success', 'Activity archived. Its history is preserved.');
        }

        return $this->redirectToRoute('app_activity_index');
    }

    /**
     * Parse a user-supplied date string into a \DateTime, or null when invalid.
     */
    private function parseDateParam(?string $value, string $default = 'now'): ?\DateTime
    {
        if ($value === null || trim($value) === '') {
            $value = $default;
        }
        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
