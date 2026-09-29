<?php

namespace App\Controller;

use App\Entity\Playbook;
use App\Entity\PlaybookRun;
use App\Repository\PlaybookRepository;
use App\Repository\PlaybookRunRepository;
use App\Service\PlaybookEngine;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/playbooks')]
#[IsGranted('ROLE_USER')]
class PlaybookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaybookRepository $playbookRepository,
        private PlaybookRunRepository $playbookRunRepository,
        private PlaybookEngine $playbookEngine,
        private GuidanceNotificationService $guidanceService,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_playbook_index', methods: ['GET'])]
    public function index(): Response
    {
        $playbooks = $this->playbookRepository->createQueryBuilder('p')
            ->andWhere('p.archivedAt IS NULL')
            ->orderBy('p.priority', 'DESC')
            ->getQuery()
            ->getResult();
        
        // Calculate stats for each playbook
        $playbookStats = [];
        foreach ($playbooks as $playbook) {
            $runs = $this->playbookRunRepository->findByPlaybook($playbook, 100);
            $totalRuns = count($runs);
            $successfulRuns = count(array_filter($runs, fn($run) => $run->getStatus() === 'completed'));
            
            $playbookStats[$playbook->getId()] = [
                'total_runs' => $totalRuns,
                'successful_runs' => $successfulRuns,
                'success_rate' => $totalRuns > 0 ? ($successfulRuns / $totalRuns) * 100 : 0,
                'last_run' => $runs[0] ?? null
            ];
        }
        
        return $this->render('playbook/index.html.twig', [
            'playbooks' => $playbooks,
            'playbookStats' => $playbookStats,
        ]);
    }

    #[Route('/new', name: 'app_playbook_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('playbook_new', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $playbook = new Playbook();
            $playbook->setName($request->request->get('name'));
            $playbook->setDescription($request->request->get('description'));
            $playbook->setPriority((int) $request->request->get('priority', 100));
            $playbook->setIsActive($request->request->get('is_active', '1') === '1');
            $playbook->setNotes($request->request->get('notes'));
            
            // Parse trigger rules
            $triggerRules = $request->request->get('trigger_rules', '[]');
            $playbook->setTriggerRules($triggerRules);
            
            // Parse actions
            $actions = $request->request->get('actions', '[]');
            $playbook->setActions($actions);
            
            $this->entityManager->persist($playbook);
            $this->entityManager->flush();
            
            // Provide guidance after playbook creation
            $this->guidanceService->afterPlaybookCreated($playbook->getId(), $playbook->getName());
            
            $this->addFlash('success', $this->translator->trans('playbook.flash.created'));
            return $this->redirectToRoute('app_playbook_show', ['id' => $playbook->getId()]);
        }
        
        return $this->render('playbook/new.html.twig', [
            'availableTriggers' => $this->getAvailableTriggers(),
            'availableActions' => $this->getAvailableActions(),
        ]);
    }

    #[Route('/{id}', name: 'app_playbook_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Playbook $playbook): Response
    {
        if ($playbook->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Playbook not found');
        }

        $runs = $this->playbookRunRepository->findByPlaybook($playbook, 50);
        
        // Calculate statistics
        $stats = [
            'total_runs' => count($runs),
            'completed' => count(array_filter($runs, fn($r) => $r->getStatus() === 'completed')),
            'failed' => count(array_filter($runs, fn($r) => $r->getStatus() === 'failed')),
            'pending' => count(array_filter($runs, fn($r) => $r->getStatus() === 'pending')),
        ];
        
        return $this->render('playbook/show.html.twig', [
            'playbook' => $playbook,
            'runs' => $runs,
            'stats' => $stats,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_playbook_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, Playbook $playbook): Response
    {
        if ($playbook->isArchived()) {
            $this->addFlash('error', 'This playbook is archived and read-only.');

            return $this->redirectToRoute('app_playbook_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('playbook_edit', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $playbook->setName($request->request->get('name'));
            $playbook->setDescription($request->request->get('description'));
            $playbook->setPriority((int) $request->request->get('priority', 100));
            $playbook->setIsActive($request->request->get('is_active', '1') === '1');
            $playbook->setNotes($request->request->get('notes'));
            
            // Update trigger rules
            $triggerRules = $request->request->get('trigger_rules', '[]');
            $playbook->setTriggerRules($triggerRules);
            
            // Update actions
            $actions = $request->request->get('actions', '[]');
            $playbook->setActions($actions);
            
            $playbook->setUpdatedAt(new \DateTime());
            
            $this->entityManager->flush();
            
            $this->addFlash('success', $this->translator->trans('playbook.flash.updated'));
            return $this->redirectToRoute('app_playbook_show', ['id' => $playbook->getId()]);
        }
        
        return $this->render('playbook/edit.html.twig', [
            'playbook' => $playbook,
            'availableTriggers' => $this->getAvailableTriggers(),
            'availableActions' => $this->getAvailableActions(),
        ]);
    }

    #[Route('/{id}/delete', name: 'app_playbook_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Playbook $playbook): Response
    {
        if (!$this->isCsrfTokenValid('playbook_delete_' . $playbook->getId(), $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Playbook execution history (runs) is CRM history: archive the
        // playbook instead of cascading its runs away.
        $playbook->archive($this->getUser(), 'Archived from playbooks list');
        $playbook->setIsActive(false);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('playbook.flash.deleted'));
        return $this->redirectToRoute('app_playbook_index');
    }

    #[Route('/{id}/toggle', name: 'app_playbook_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, Playbook $playbook): JsonResponse
    {
        if ($playbook->isArchived()) {
            return new JsonResponse(['error' => 'This playbook is archived and cannot be reactivated.'], 409);
        }

        if (!$this->isCsrfTokenValid('playbook_toggle_' . $playbook->getId(), $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $playbook->setIsActive(!$playbook->isActive());
        $playbook->setUpdatedAt(new \DateTime());
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'is_active' => $playbook->isActive(),
            'message' => $playbook->isActive() ? 'Playbook activated' : 'Playbook deactivated'
        ]);
    }

    #[Route('/builder/triggers/{id}', name: 'app_playbook_builder_triggers', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function builderTriggers(Request $request, Playbook $playbook): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('playbook_builder_triggers', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $payload = $request->request->get('trigger_rules', '[]');
            $decoded = json_decode($payload, true);

            if (!is_array($decoded)) {
                $this->addFlash('error', $this->translator->trans('playbook.flash.invalid_rules'));
            } else {
                $playbook->setTriggerRules(json_encode($decoded));
                $playbook->setUpdatedAt(new \DateTime());
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('playbook.flash.updated'));
                return $this->redirectToRoute('app_playbook_builder_triggers', ['id' => $playbook->getId()]);
            }
        }

        return $this->render('playbook/builder_triggers.html.twig', [
            'playbook' => $playbook,
            'availableTriggers' => $this->getAvailableTriggers(),
            'currentTriggers' => $this->decodeJsonArray($playbook->getTriggerRules()),
        ]);
    }

    #[Route('/builder/actions/{id}', name: 'app_playbook_builder_actions', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function builderActions(Request $request, Playbook $playbook): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('playbook_builder_actions', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            $payload = $request->request->get('actions', '[]');
            $decoded = json_decode($payload, true);

            if (!is_array($decoded)) {
                $this->addFlash('error', $this->translator->trans('playbook.flash.invalid_actions'));
            } else {
                $playbook->setActions(json_encode($decoded));
                $playbook->setUpdatedAt(new \DateTime());
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('playbook.flash.updated'));
                return $this->redirectToRoute('app_playbook_builder_actions', ['id' => $playbook->getId()]);
            }
        }

        return $this->render('playbook/builder_actions.html.twig', [
            'playbook' => $playbook,
            'availableActions' => $this->getAvailableActions(),
            'currentActions' => $this->decodeJsonArray($playbook->getActions()),
        ]);
    }

    private function decodeJsonArray(?string $value): array
    {
        $decoded = json_decode($value ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getAvailableTriggers(): array
    {
        return [
            'abm' => [
                'label' => 'ABM & Visitor Tracking',
                'triggers' => [
                    ['field' => 'abm_hits_7d', 'label' => 'Page views in last 7 days', 'type' => 'number'],
                    ['field' => 'company_tier', 'label' => 'Company tier', 'type' => 'select', 'options' => ['A', 'B', 'C']],
                    ['field' => 'engagement_score', 'label' => 'Engagement score', 'type' => 'number'],
                    ['field' => 'visited_pricing', 'label' => 'Visited pricing page', 'type' => 'boolean'],
                ]
            ],
            'lead' => [
                'label' => 'Lead Management',
                'triggers' => [
                    ['field' => 'lead_score', 'label' => 'Lead score', 'type' => 'number'],
                    ['field' => 'lead_status', 'label' => 'Lead status', 'type' => 'select', 'options' => ['NEW', 'CONTACTED', 'QUALIFIED', 'LOST']],
                    ['field' => 'lead_source', 'label' => 'Lead source', 'type' => 'select', 'options' => ['Website', 'LinkedIn', 'Referral', 'Trade Show']],
                ]
            ],
            'email' => [
                'label' => 'Email Engagement',
                'triggers' => [
                    ['field' => 'email_opened', 'label' => 'Email opened', 'type' => 'boolean'],
                    ['field' => 'email_clicked', 'label' => 'Email clicked', 'type' => 'boolean'],
                    ['field' => 'email_replied', 'label' => 'Email replied', 'type' => 'boolean'],
                ]
            ],
            'quote' => [
                'label' => 'Quote & RFQ',
                'triggers' => [
                    ['field' => 'quote_value', 'label' => 'Quote value', 'type' => 'currency'],
                    ['field' => 'quote_status', 'label' => 'Quote status', 'type' => 'select', 'options' => ['DRAFT', 'SENT', 'ACCEPTED', 'REJECTED']],
                    ['field' => 'days_pending', 'label' => 'Days pending', 'type' => 'number'],
                ]
            ],
        ];
    }

    private function getAvailableActions(): array
    {
        return [
            'activity' => [
                'label' => 'Create Activity',
                'description' => 'Create a task, call, or meeting in CRM',
                'params' => [
                    ['name' => 'type', 'label' => 'Activity type', 'type' => 'select', 'options' => ['CALL', 'EMAIL', 'MEETING', 'TASK']],
                    ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'options' => ['LOW', 'MEDIUM', 'HIGH', 'URGENT']],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ]
            ],
            'email' => [
                'label' => 'Send Email',
                'description' => 'Send automated email from template',
                'params' => [
                    ['name' => 'template', 'label' => 'Email template', 'type' => 'select', 'options' => ['welcome', 'follow_up', 'hot_lead', 'quote_reminder']],
                    ['name' => 'delay_hours', 'label' => 'Delay (hours)', 'type' => 'number'],
                ]
            ],
            'lead' => [
                'label' => 'Update Lead',
                'description' => 'Update lead score or status',
                'params' => [
                    ['name' => 'score_delta', 'label' => 'Score change', 'type' => 'number'],
                    ['name' => 'status', 'label' => 'New status', 'type' => 'select', 'options' => ['NEW', 'CONTACTED', 'QUALIFIED', 'LOST']],
                ]
            ],
            'assign' => [
                'label' => 'Assign Owner',
                'description' => 'Assign lead/company to user',
                'params' => [
                    ['name' => 'user_id', 'label' => 'User', 'type' => 'user_select'],
                ]
            ],
            'webhook' => [
                'label' => 'Webhook',
                'description' => 'Send data to external URL',
                'params' => [
                    ['name' => 'url', 'label' => 'Webhook URL', 'type' => 'url'],
                    ['name' => 'payload', 'label' => 'JSON payload', 'type' => 'textarea'],
                ]
            ],
        ];
    }
}
