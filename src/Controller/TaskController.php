<?php

namespace App\Controller;

use App\Entity\Task;
use App\Form\TaskType;
use App\Repository\TaskRepository;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Security\Voter\TaskVoter;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/tasks')]
#[IsGranted('ROLE_USER')]
class TaskController extends AbstractController
{
    public function __construct(
        private TaskRepository $taskRepository,
        private EntityManagerInterface $entityManager,
        private GuidanceNotificationService $guidanceService,
        private UserRepository $userRepository,
        private CompanyRepository $companyRepository,
        private TranslatorInterface $translator
    ) {}

    #[Route('', name: 'app_task_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $view = $request->query->get('view', 'list');
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $assignee = $request->query->get('assignee');
        $company = $request->query->get('company');
        $search = $request->query->get('search');
        // "all" is an ADMIN scope toggle — gating it on the role here (not
        // just in the view) closes the ?all=1 bypass any ROLE_USER had.
        $showAll = $request->query->getBoolean('all', false)
            && $this->isGranted(TaskVoter::VIEW_ALL);

        $qb = $this->taskRepository->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')
            ->leftJoin('t.createdBy', 'cb')
            ->leftJoin('t.company', 'c')
            ->leftJoin('t.contact', 'co')
            ->addSelect('a', 'cb', 'c', 'co')
            ->andWhere('t.archivedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC');

        // Filter by user unless showing all
        if (!$showAll) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

        if ($status) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        if ($priority) {
            $qb->andWhere('t.priority = :priority')
               ->setParameter('priority', $priority);
        }

        if ($assignee) {
            $qb->andWhere('t.assignedTo = :assignee')
               ->setParameter('assignee', $assignee);
        }

        if ($company) {
            $qb->andWhere('t.company = :company')
               ->setParameter('company', $company);
        }

        if ($search) {
            $qb->andWhere('t.title LIKE :search OR t.description LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $tasks = $qb->getQuery()->getResult();

        // Get statistics
        $stats = $this->taskRepository->getStatistics($showAll ? null : $user);

        // Get users and companies for filters (bounded; the currently-selected
        // filter values are always included so the filters keep working)
        $users = $this->userRepository->findBy(['active' => true], ['firstName' => 'ASC'], 300);

        if ($assignee !== null && !in_array((int) $assignee, array_map(static fn($u) => $u->getId(), $users), true)) {
            $selectedUser = $this->userRepository->find((int) $assignee);
            if ($selectedUser) {
                $users[] = $selectedUser;
            }
        }

        $companies = $this->companyRepository->findBy([], ['name' => 'ASC'], 300);

        if ($company !== null && !in_array((int) $company, array_map(static fn($c) => $c->getId(), $companies), true)) {
            $selectedCompany = $this->companyRepository->find((int) $company);
            if ($selectedCompany) {
                $companies[] = $selectedCompany;
            }
        }

        return $this->render('task/index.html.twig', [
            'tasks' => $tasks,
            'stats' => $stats,
            'view' => $view,
            'users' => $users,
            'companies' => $companies,
            'current_status' => $status,
            'current_priority' => $priority,
            'current_assignee' => $assignee,
            'current_company' => $company,
            'current_search' => $search,
            'show_all' => $showAll,
            'statuses' => Task::STATUS_LABELS,
            'priorities' => Task::PRIORITY_LABELS,
        ]);
    }

    #[Route('/kanban', name: 'app_task_kanban', methods: ['GET'])]
    public function kanban(Request $request): Response
    {
        $user = $this->getUser();
        // ?all=1 previously leaked EVERY user's board to any account —
        // the admin condition now lives in the voter.
        $showAll = $request->query->getBoolean('all', false)
            && $this->isGranted(TaskVoter::VIEW_ALL);

        $tasksByStatus = $this->taskRepository->findGroupedByStatus(
            $showAll ? null : $user
        );

        $stats = $this->taskRepository->getStatistics($showAll ? null : $user);

        return $this->render('task/kanban.html.twig', [
            // The template reads tasks_by_status (snake_case) — the
            // camelCase key rendered an always-empty board.
            'tasks_by_status' => $tasksByStatus,
            'stats' => $stats,
            'statuses' => Task::STATUS_LABELS,
            'show_all' => $showAll,
        ]);
    }

    #[Route('/new', name: 'app_task_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $task = new Task();
        $task->setCreatedBy($this->getUser());
        $task->setAssignedTo($this->getUser());

        // Pre-fill from query parameters
        if ($companyId = $request->query->get('company')) {
            $company = $this->companyRepository->find($companyId);
            if ($company) {
                $task->setCompany($company);
            }
        }

        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle tags
            $tagsString = $form->get('tags')->getData();
            if ($tagsString) {
                $tags = array_map('trim', explode(',', $tagsString));
                $task->setTags($tags);
            }

            $this->entityManager->persist($task);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('task.flash.created'));

            // Redirect based on where we came from
            if ($request->query->get('redirect') === 'kanban') {
                return $this->redirectToRoute('app_task_kanban');
            }

            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        return $this->render('task/new.html.twig', [
            'task' => $task,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_task_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Task $task): Response
    {
        // IDOR fix: numeric-ID enumeration of other users' tasks.
        $this->denyAccessUnlessGranted(TaskVoter::VIEW, $task);

        return $this->render('task/show.html.twig', [
            'task' => $task,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_task_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Task $task): Response
    {
        $this->denyAccessUnlessGranted(TaskVoter::MODIFY, $task);

        $form = $this->createForm(TaskType::class, $task);
        
        // Pre-fill tags field
        if ($task->getTags()) {
            $form->get('tags')->setData(implode(', ', $task->getTags()));
        }
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Handle tags
            $tagsString = $form->get('tags')->getData();
            if ($tagsString) {
                $tags = array_map('trim', explode(',', $tagsString));
                $task->setTags($tags);
            } else {
                $task->setTags(null);
            }

            $task->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('task.flash.updated'));

            return $this->redirectToRoute('app_task_show', ['id' => $task->getId()]);
        }

        return $this->render('task/edit.html.twig', [
            'task' => $task,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_task_delete', methods: ['POST'])]
    public function delete(Request $request, Task $task): Response
    {
        $this->denyAccessUnlessGranted(TaskVoter::ARCHIVE, $task);

        if ($this->isCsrfTokenValid('delete' . $task->getId(), $request->request->get('_token'))) {
            // Completed tasks are CRM execution history (like activities):
            // archive instead of hard-delete; open tasks may be removed.
            if ($task->getCompletedAt() !== null) {
                $task->archive();
                $this->entityManager->flush();
                $this->addFlash('success', 'Task archived (completed-task history preserved).');
            } else {
                $this->entityManager->remove($task);
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('task.flash.deleted'));
            }
        }

        return $this->redirectToRoute('app_task_index');
    }

    #[Route('/{id}/toggle', name: 'app_task_toggle', methods: ['POST'])]
    public function toggle(Request $request, Task $task): Response
    {
        $this->denyAccessUnlessGranted(TaskVoter::MODIFY, $task);

        if ($this->isCsrfTokenValid('toggle' . $task->getId(), $request->request->get('_token'))) {
            // transitionTo() is the authoritative lifecycle: leaving DONE
            // clears completedAt, entering DONE stamps it.
            $task->transitionTo(
                $task->getStatus() === Task::STATUS_DONE ? Task::STATUS_TODO : Task::STATUS_DONE
            );
            $this->entityManager->flush();
        }

        // Return to the page the toggle came from (same-host only)
        $referer = $request->headers->get('referer');
        if ($referer && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_task_index');
    }

    #[Route('/{id}/complete', name: 'app_task_complete', methods: ['POST'])]
    public function complete(Request $request, Task $task): Response
    {
        $this->denyAccessUnlessGranted(TaskVoter::MODIFY, $task);

        if ($this->isCsrfTokenValid('complete' . $task->getId(), $request->request->get('_token'))) {
            $task->transitionTo(Task::STATUS_DONE);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('task.flash.completed'));
        }

        return $this->redirectToRoute('app_task_index');
    }

    #[Route('/api/update-status', name: 'app_task_api_update_status', methods: ['POST'])]
    public function apiUpdateStatus(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('update_status', $data['_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        if (!$data || !isset($data['taskId']) || !isset($data['status'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $task = $this->taskRepository->find($data['taskId']);
        if (!$task) {
            return new JsonResponse(['error' => 'Task not found'], 404);
        }

        if (!$this->isGranted(TaskVoter::MODIFY, $task)) {
            return new JsonResponse(['error' => 'Not authorized to modify this task'], 403);
        }

        if (!in_array($data['status'], Task::STATUSES)) {
            return new JsonResponse(['error' => 'Invalid status'], 400);
        }

        try {
            // transitionTo() keeps status/completedAt consistent (leaving
            // DONE clears completedAt) and rejects archived tasks.
            $task->transitionTo($data['status']);
        } catch (\InvalidArgumentException | \LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        if (isset($data['sortOrder'])) {
            $task->setSortOrder((int) $data['sortOrder']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'task' => [
                'id' => $task->getId(),
                'status' => $task->getStatus(),
                'statusLabel' => $task->getStatusLabel(),
            ]
        ]);
    }

    #[Route('/api/reorder', name: 'app_task_api_reorder', methods: ['POST'])]
    public function apiReorder(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('reorder', $data['_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        if (!$data || !isset($data['tasks'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        foreach ($data['tasks'] as $taskData) {
            $task = $this->taskRepository->find($taskData['id']);
            if ($task && $this->isGranted(TaskVoter::MODIFY, $task)) {
                $task->setSortOrder($taskData['sortOrder']);
                if (isset($taskData['status'])) {
                    try {
                        $task->transitionTo($taskData['status']);
                    } catch (\InvalidArgumentException | \LogicException) {
                        continue; // invalid/archived — skip, don't fail the batch
                    }
                } else {
                    $task->setUpdatedAt(new \DateTime());
                }
            }
        }

        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/my-day', name: 'app_task_my_day', methods: ['GET'])]
    public function myDay(): Response
    {
        $user = $this->getUser();

        $overdueTasks = $this->taskRepository->findOverdue($user);
        $todayTasks = $this->taskRepository->findDueToday($user);
        $weekTasks = $this->taskRepository->findDueThisWeek($user);
        $stats = $this->taskRepository->getStatistics($user);

        return $this->render('task/my_day.html.twig', [
            'overdueTasks' => $overdueTasks,
            'todayTasks' => $todayTasks,
            'weekTasks' => $weekTasks,
            'stats' => $stats,
        ]);
    }

    #[Route('/quick-add', name: 'app_task_quick_add', methods: ['POST'])]
    public function quickAdd(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('quick_add', $data['_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        if (!$data || empty($data['title'])) {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }

        $task = new Task();
        $task->setTitle($data['title']);
        $task->setCreatedBy($this->getUser());
        $task->setAssignedTo($this->getUser());
        
        if (isset($data['dueDate'])) {
            $task->setDueDate(new \DateTime($data['dueDate']));
        }
        
        if (isset($data['priority'])) {
            $task->setPriority($data['priority']);
        }
        
        if (isset($data['type'])) {
            $task->setType($data['type']);
        }

        if (isset($data['companyId'])) {
            $company = $this->companyRepository->find($data['companyId']);
            if ($company) {
                $task->setCompany($company);
            }
        }

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'task' => [
                'id' => $task->getId(),
                'title' => $task->getTitle(),
                'status' => $task->getStatus(),
                'priority' => $task->getPriority(),
                'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            ]
        ]);
    }

    private function canModify(Task $task): bool
    {
        return $this->isGranted(TaskVoter::MODIFY, $task);
    }
}
