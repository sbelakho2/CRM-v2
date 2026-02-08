<?php

namespace App\Controller;

use App\Service\AutonomousSalesOrchestratorService;
use App\Service\AutonomousSalesSettingsService;
use App\Service\ThompsonSamplerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/autonomous-sales')]
class AutonomousSalesDashboardController extends AbstractController
{
    #[Route('', name: 'autonomous_sales_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(AutonomousSalesOrchestratorService $orchestrator, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): Response
    {
        $systemStats = $orchestrator->getStats();
        $types = [
            'subject_line' => 'Subject Lines',
            'template' => 'Templates',
            'send_time' => 'Send Times',
        ];

        $banditStats = [];
        $banditArms = [];

        foreach ($types as $type => $label) {
            $banditStats[$type] = $thompsonSampler->getBanditStats($type);
            $banditArms[$type] = $thompsonSampler->getArmsWithStats($type);
        }

        return $this->render('autonomous_sales/index.html.twig', [
            'types' => $types,
            'bandit_stats' => $banditStats,
            'bandit_arms' => $banditArms,
            'system_stats' => $systemStats,
            'autonomous_enabled' => $settingsService->isEnabled(),
        ]);
    }

    #[Route('/toggle', name: 'autonomous_sales_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('autonomous_sales_toggle', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $enabled = $settingsService->toggle();
        $this->addFlash('success', $enabled ? 'Autonomous Sales enabled.' : 'Autonomous Sales disabled.');

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/arms/new', name: 'autonomous_sales_arm_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createArm(Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Autonomous Sales is disabled.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $armType = (string) $request->request->get('armType', 'subject_line');
        $armName = trim((string) $request->request->get('armName', ''));
        $armValue = trim((string) $request->request->get('armValue', ''));

        if ($armName === '' || $armValue === '') {
            $this->addFlash('error', 'Arm name and value are required.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $thompsonSampler->createArm($armType, $armName, $armValue);
        $this->addFlash('success', 'Bandit arm created.');

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/arms/{id}/outcome', name: 'autonomous_sales_arm_outcome', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function recordOutcome(int $id, Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Autonomous Sales is disabled.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_arm_outcome_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $success = $request->request->get('result') === 'success';
        $eventType = (string) $request->request->get('eventType', 'open');

        $thompsonSampler->recordOutcome($id, $success, $eventType);
        $this->addFlash('success', $success ? 'Recorded success.' : 'Recorded failure.');

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/seed', name: 'autonomous_sales_seed', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function seedDefaults(Request $request, ThompsonSamplerService $thompsonSampler, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Autonomous Sales is disabled.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_seed', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $type = (string) $request->request->get('seedType', 'subject_line');

        if ($type !== 'subject_line') {
            $this->addFlash('warning', 'Default seeds are available only for subject lines.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $created = $thompsonSampler->seedDefaultSubjectLineArms();
        $this->addFlash('success', sprintf('Seeded %d subject line arms.', count($created)));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/initialize', name: 'autonomous_sales_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function initializeSystem(Request $request, AutonomousSalesOrchestratorService $orchestrator, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Autonomous Sales is disabled.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_initialize', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $result = $orchestrator->initialize();
        $this->addFlash('success', sprintf(
            'Initialized: %d templates, %d arms, %d competitors.',
            $result['templates'] ?? 0,
            $result['arms'] ?? 0,
            $result['competitors'] ?? 0
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }

    #[Route('/score-leads', name: 'autonomous_sales_score_leads', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function scoreLeads(Request $request, AutonomousSalesOrchestratorService $orchestrator, AutonomousSalesSettingsService $settingsService): RedirectResponse
    {
        if (!$settingsService->isEnabled()) {
            $this->addFlash('error', 'Autonomous Sales is disabled.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        if (!$this->isCsrfTokenValid('autonomous_sales_score_leads', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid request token.');
            return $this->redirectToRoute('autonomous_sales_index');
        }

        $limit = max(1, (int) $request->request->get('limit', 50));
        $result = $orchestrator->scoreLeads($limit);

        $this->addFlash('success', sprintf(
            'Scored %d leads (Hot: %d, Warm: %d, Cold: %d, Ice: %d).',
            $result['scored'] ?? 0,
            $result['byTier']['hot'] ?? 0,
            $result['byTier']['warm'] ?? 0,
            $result['byTier']['cold'] ?? 0,
            $result['byTier']['ice'] ?? 0
        ));

        return $this->redirectToRoute('autonomous_sales_index');
    }
}
