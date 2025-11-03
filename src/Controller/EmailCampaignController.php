<?php

namespace App\Controller;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Entity\EmailTemplate;
use App\Entity\EmailSegment;
use App\Form\EmailCampaignType;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use App\Repository\ContactRepository;
use App\Service\EmailCampaignService;
use App\Service\EmailTemplateService;
use App\Service\EmailSegmentService;
use App\Service\EmailSchedulerService;
use App\Service\EmailAnalyticsService;
use App\Service\EmailAbTestService;
use App\Service\EmailDripCampaignService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/email-campaigns')]
class EmailCampaignController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private EmailSendRepository $sendRepository,
        private ContactRepository $contactRepository,
        private EmailCampaignService $campaignService,
        private EmailTemplateService $templateService,
        private EmailSegmentService $segmentService,
        private EmailSchedulerService $schedulerService,
        private EmailAnalyticsService $analyticsService,
        private EmailAbTestService $abTestService,
        private EmailDripCampaignService $dripCampaignService
    ) {}

    #[Route('/', name: 'app_email_campaign_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status', 'all');
        $language = $request->query->get('language', 'all');

        $qb = $this->campaignRepository->createQueryBuilder('c')
            ->orderBy('c.id', 'DESC');

        if ($status === 'active') {
            $qb->andWhere('c.active = :active')->setParameter('active', true);
        } elseif ($status === 'inactive') {
            $qb->andWhere('c.active = :active')->setParameter('active', false);
        }

        if ($language !== 'all') {
            $qb->andWhere('c.language = :language')->setParameter('language', $language);
        }

        $campaigns = $qb->getQuery()->getResult();

        // Calculate metrics for each campaign
        $campaignMetrics = [];
        foreach ($campaigns as $campaign) {
            $campaignMetrics[$campaign->getId()] = $this->campaignService->getCampaignMetrics($campaign);
        }

        return $this->render('email_campaign/index.html.twig', [
            'campaigns' => $campaigns,
            'campaignMetrics' => $campaignMetrics,
            'currentStatus' => $status,
            'currentLanguage' => $language,
        ]);
    }

    #[Route('/new', name: 'app_email_campaign_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $campaign = new EmailCampaign();
        $form = $this->createForm(EmailCampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($campaign);
            $this->entityManager->flush();

            $this->addFlash('success', 'Email campaign created successfully.');
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('email_campaign/new.html.twig', [
            'campaign' => $campaign,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_email_campaign_show', methods: ['GET'])]
    public function show(EmailCampaign $campaign): Response
    {
        $metrics = $this->campaignService->getCampaignMetrics($campaign);
        
        // Get sends grouped by touch number
        $sendsByTouch = [];
        foreach ($campaign->getSends() as $send) {
            $touchNum = $send->getTouchNumber();
            if (!isset($sendsByTouch[$touchNum])) {
                $sendsByTouch[$touchNum] = [];
            }
            $sendsByTouch[$touchNum][] = $send;
        }

        // Calculate per-touch metrics
        $touchMetrics = [];
        for ($i = 1; $i <= $campaign->getTouchCount(); $i++) {
            $sends = $sendsByTouch[$i] ?? [];
            $total = count($sends);
            
            if ($total > 0) {
                $opened = 0;
                $clicked = 0;
                $replied = 0;
                
                foreach ($sends as $send) {
                    if ($send->isOpened()) $opened++;
                    if ($send->isClicked()) $clicked++;
                    if ($send->isReplied()) $replied++;
                }
                
                $touchMetrics[$i] = [
                    'total' => $total,
                    'opened' => $opened,
                    'clicked' => $clicked,
                    'replied' => $replied,
                    'open_rate' => ($opened / $total) * 100,
                    'click_rate' => ($clicked / $total) * 100,
                    'reply_rate' => ($replied / $total) * 100,
                ];
            } else {
                $touchMetrics[$i] = [
                    'total' => 0,
                    'opened' => 0,
                    'clicked' => 0,
                    'replied' => 0,
                    'open_rate' => 0,
                    'click_rate' => 0,
                    'reply_rate' => 0,
                ];
            }
        }

        return $this->render('email_campaign/show.html.twig', [
            'campaign' => $campaign,
            'metrics' => $metrics,
            'touchMetrics' => $touchMetrics,
            'sendsByTouch' => $sendsByTouch,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_email_campaign_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, EmailCampaign $campaign): Response
    {
        $form = $this->createForm(EmailCampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Email campaign updated successfully.');
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('email_campaign/edit.html.twig', [
            'campaign' => $campaign,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_email_campaign_delete', methods: ['POST'])]
    public function delete(Request $request, EmailCampaign $campaign): Response
    {
        if ($this->isCsrfTokenValid('delete'.$campaign->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($campaign);
            $this->entityManager->flush();

            $this->addFlash('success', 'Email campaign deleted successfully.');
        }

        return $this->redirectToRoute('app_email_campaign_index');
    }

    #[Route('/{id}/toggle-active', name: 'app_email_campaign_toggle_active', methods: ['POST'])]
    public function toggleActive(Request $request, EmailCampaign $campaign): Response
    {
        if ($this->isCsrfTokenValid('toggle'.$campaign->getId(), $request->request->get('_token'))) {
            $campaign->setActive(!$campaign->isActive());
            $this->entityManager->flush();

            $status = $campaign->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', "Campaign {$status} successfully.");
        }

        return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
    }

    #[Route('/{id}/send', name: 'app_email_campaign_send', methods: ['GET', 'POST'])]
    public function send(Request $request, EmailCampaign $campaign): Response
    {
        if ($request->isMethod('POST')) {
            $contactIds = $request->request->all('contacts');
            $touchNumber = (int) $request->request->get('touch_number', 1);

            if (empty($contactIds)) {
                $this->addFlash('error', 'Please select at least one contact.');
                return $this->redirectToRoute('app_email_campaign_send', ['id' => $campaign->getId()]);
            }

            $sentCount = 0;
            foreach ($contactIds as $contactId) {
                $contact = $this->contactRepository->find($contactId);
                if ($contact) {
                    $this->campaignService->sendToContact($campaign, $contact, $touchNumber);
                    $sentCount++;
                }
            }

            $this->addFlash('success', "Sent {$sentCount} emails successfully.");
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        // Get all contacts
        $contacts = $this->contactRepository->findAll();

        return $this->render('email_campaign/send.html.twig', [
            'campaign' => $campaign,
            'contacts' => $contacts,
        ]);
    }

    #[Route('/{id}/analytics', name: 'app_email_campaign_analytics', methods: ['GET'])]
    public function analytics(EmailCampaign $campaign): Response
    {
        $metrics = $this->campaignService->getCampaignMetrics($campaign);
        
        // Get timeline data (sends over time)
        $qb = $this->entityManager->createQueryBuilder();
        $sendsByDate = $qb->select('DATE(s.sentAt) as date, COUNT(s.id) as count')
            ->from(EmailSend::class, 's')
            ->where('s.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->getQuery()
            ->getResult();

        // Get top performing contacts (by opens, clicks, replies)
        $qb = $this->entityManager->createQueryBuilder();
        $topContacts = $qb->select('c.id', 'c.firstName', 'c.lastName', 'c.email', 
                                   'SUM(CASE WHEN s.opened = true THEN 1 ELSE 0 END) as opens',
                                   'SUM(CASE WHEN s.clicked = true THEN 1 ELSE 0 END) as clicks',
                                   'SUM(CASE WHEN s.replied = true THEN 1 ELSE 0 END) as replies')
            ->from(EmailSend::class, 's')
            ->join('s.contact', 'c')
            ->where('s.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->groupBy('c.id', 'c.firstName', 'c.lastName', 'c.email')
            ->orderBy('opens', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('email_campaign/analytics.html.twig', [
            'campaign' => $campaign,
            'metrics' => $metrics,
            'sendsByDate' => $sendsByDate,
            'topContacts' => $topContacts,
        ]);
    }

    #[Route('/track/{id}/open', name: 'app_email_send_track_open', methods: ['GET'])]
    public function trackOpen(EmailSend $send): Response
    {
        if (!$send->isOpened()) {
            $this->campaignService->markOpened($send);
        }

        // Return 1x1 transparent pixel
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return new Response($pixel, 200, ['Content-Type' => 'image/gif']);
    }

    #[Route('/track/{id}/click', name: 'app_email_send_track_click', methods: ['GET'])]
    public function trackClick(Request $request, EmailSend $send): Response
    {
        $this->campaignService->markClicked($send);

        // Redirect to the actual URL
        $url = $request->query->get('url', '/');
        return $this->redirect($url);
    }

    /**
     * Campaign wizard - Step 2: Template Selection
     */
    #[Route('/{id}/wizard/template', name: 'app_email_campaign_wizard_template', methods: ['GET', 'POST'])]
    public function wizardTemplate(EmailCampaign $campaign, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $templateId = $request->request->get('template_id');
            
            if ($templateId === 'new') {
                return $this->redirectToRoute('app_email_campaign_template_builder', [
                    'campaignId' => $campaign->getId(),
                ]);
            }

            $template = $this->entityManager->getRepository(EmailTemplate::class)->find($templateId);
            if ($template) {
                $campaign->setTemplate($template);
                $campaign->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }

            return $this->redirectToRoute('app_email_campaign_wizard_segment', [
                'id' => $campaign->getId(),
            ]);
        }

        $templates = $this->templateService->getActiveTemplates();

        return $this->render('email_campaign/wizard/template.html.twig', [
            'campaign' => $campaign,
            'templates' => $templates,
        ]);
    }

    /**
     * Campaign wizard - Step 3: Segment Selection
     */
    #[Route('/{id}/wizard/segment', name: 'app_email_campaign_wizard_segment', methods: ['GET', 'POST'])]
    public function wizardSegment(EmailCampaign $campaign, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $segmentId = $request->request->get('segment_id');
            
            if ($segmentId === 'new') {
                return $this->redirectToRoute('app_email_campaign_segment_builder', [
                    'campaignId' => $campaign->getId(),
                ]);
            }

            $segment = $this->entityManager->getRepository(EmailSegment::class)->find($segmentId);
            if ($segment) {
                $campaign->setSegment($segment);
                $campaign->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
            }

            return $this->redirectToRoute('app_email_campaign_wizard_schedule', [
                'id' => $campaign->getId(),
            ]);
        }

        $segments = $this->entityManager->getRepository(EmailSegment::class)
            ->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('email_campaign/wizard/segment.html.twig', [
            'campaign' => $campaign,
            'segments' => $segments,
        ]);
    }

    /**
     * Campaign wizard - Step 4: Schedule
     */
    #[Route('/{id}/wizard/schedule', name: 'app_email_campaign_wizard_schedule', methods: ['GET', 'POST'])]
    public function wizardSchedule(EmailCampaign $campaign, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $sendOption = $request->request->get('send_option');
            
            if ($sendOption === 'now') {
                $campaign->setScheduledAt(new \DateTimeImmutable());
            } elseif ($sendOption === 'scheduled') {
                $scheduledDate = $request->request->get('scheduled_date');
                $scheduledTime = $request->request->get('scheduled_time');
                $campaign->setScheduledAt(new \DateTimeImmutable($scheduledDate . ' ' . $scheduledTime));
            }

            $campaign->setSendTimeOptimization($request->request->get('optimize_send_time', false));
            $campaign->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return $this->redirectToRoute('app_email_campaign_wizard_review', [
                'id' => $campaign->getId(),
            ]);
        }

        return $this->render('email_campaign/wizard/schedule.html.twig', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Campaign wizard - Step 5: Review & Launch
     */
    #[Route('/{id}/wizard/review', name: 'app_email_campaign_wizard_review', methods: ['GET', 'POST'])]
    public function wizardReview(EmailCampaign $campaign, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'launch') {
                $this->schedulerService->scheduleCampaign(
                    $campaign,
                    $campaign->getScheduledAt(),
                    $campaign->isSendTimeOptimization()
                );

                $this->addFlash('success', 'Campaign launched successfully!');
                return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
            }

            if ($action === 'save_draft') {
                $campaign->setStatus('draft');
                $campaign->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                $this->addFlash('success', 'Campaign saved as draft.');
                return $this->redirectToRoute('app_email_campaign_index');
            }
        }

        $segment = $campaign->getSegment();
        $recipientCount = $segment ? $segment->getContactCount() : 0;

        return $this->render('email_campaign/wizard/review.html.twig', [
            'campaign' => $campaign,
            'recipientCount' => $recipientCount,
        ]);
    }

    /**
     * Template builder
     */
    #[Route('/templates/builder', name: 'app_email_campaign_template_builder', methods: ['GET', 'POST'])]
    public function templateBuilder(Request $request): Response
    {
        $campaignId = $request->query->get('campaignId');

        if ($request->isMethod('POST')) {
            $template = $this->templateService->createTemplate(
                name: $request->request->get('name'),
                subjectLine: $request->request->get('subject_line'),
                bodyHtml: $request->request->get('body_html'),
                bodyText: $request->request->get('body_text'),
                previewText: $request->request->get('preview_text'),
                category: $request->request->get('category', 'general'),
                personalizationTokens: json_decode($request->request->get('tokens', '[]'), true) ?: []
            );

            if ($campaignId) {
                $campaign = $this->entityManager->getRepository(EmailCampaign::class)->find($campaignId);
                if ($campaign) {
                    $campaign->setTemplate($template);
                    $this->entityManager->flush();
                    return $this->redirectToRoute('app_email_campaign_wizard_segment', ['id' => $campaignId]);
                }
            }

            $this->addFlash('success', 'Template created successfully!');
            return $this->redirectToRoute('app_email_campaign_templates');
        }

        return $this->render('email_campaign/template_builder.html.twig', [
            'campaignId' => $campaignId,
        ]);
    }

    /**
     * Segment builder
     */
    #[Route('/segments/builder', name: 'app_email_campaign_segment_builder', methods: ['GET', 'POST'])]
    public function segmentBuilder(Request $request): Response
    {
        $campaignId = $request->query->get('campaignId');

        if ($request->isMethod('POST')) {
            $filterRules = json_decode($request->request->get('filter_rules', '{}'), true);
            
            $segment = $this->segmentService->createSegment(
                name: $request->request->get('name'),
                description: $request->request->get('description'),
                filterRules: $filterRules,
                isActive: true
            );

            if ($campaignId) {
                $campaign = $this->entityManager->getRepository(EmailCampaign::class)->find($campaignId);
                if ($campaign) {
                    $campaign->setSegment($segment);
                    $this->entityManager->flush();
                    return $this->redirectToRoute('app_email_campaign_wizard_schedule', ['id' => $campaignId]);
                }
            }

            $this->addFlash('success', 'Segment created successfully!');
            return $this->redirectToRoute('app_email_campaign_segments');
        }

        $availableFields = $this->segmentService->getAvailableFields();

        return $this->render('email_campaign/segment_builder.html.twig', [
            'campaignId' => $campaignId,
            'availableFields' => $availableFields,
        ]);
    }

    /**
     * Templates list
     */
    #[Route('/templates', name: 'app_email_campaign_templates', methods: ['GET'])]
    public function templates(): Response
    {
        $templates = $this->templateService->getActiveTemplates();

        return $this->render('email_campaign/templates.html.twig', [
            'templates' => $templates,
        ]);
    }

    /**
     * Segments list
     */
    #[Route('/segments', name: 'app_email_campaign_segments', methods: ['GET'])]
    public function segments(): Response
    {
        $segments = $this->entityManager->getRepository(EmailSegment::class)
            ->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->render('email_campaign/segments.html.twig', [
            'segments' => $segments,
        ]);
    }

    /**
     * API: Preview template
     */
    #[Route('/api/templates/preview', name: 'app_api_template_preview', methods: ['POST'])]
    public function apiTemplatePreview(Request $request): Response
    {
        $templateId = $request->request->get('template_id');
        $template = $this->entityManager->getRepository(EmailTemplate::class)->find($templateId);

        if (!$template) {
            return $this->json(['error' => 'Template not found'], 404);
        }

        $preview = $this->templateService->generatePreview($template);

        return $this->json($preview);
    }

    /**
     * API: Calculate segment size
     */
    #[Route('/api/segments/calculate', name: 'app_api_segment_calculate', methods: ['POST'])]
    public function apiSegmentCalculate(Request $request): Response
    {
        $filterRules = json_decode($request->getContent(), true);
        
        if (!$filterRules) {
            return $this->json(['error' => 'Invalid filter rules'], 400);
        }

        $errors = $this->segmentService->validateFilterRules($filterRules);
        if (!empty($errors)) {
            return $this->json(['error' => 'Validation failed', 'errors' => $errors], 400);
        }

        // Create temporary segment to calculate count
        $tempSegment = new EmailSegment();
        $tempSegment->setName('temp');
        $tempSegment->setDescription('temp');
        $tempSegment->setFilterRulesJson($filterRules);
        $tempSegment->setIsActive(false);
        
        $contacts = $this->segmentService->getSegmentContacts($tempSegment);
        $count = count($contacts);

        return $this->json(['count' => $count]);
    }

    /**
     * API: Get available operators for field type
     */
    #[Route('/api/segments/operators/{type}', name: 'app_api_segment_operators', methods: ['GET'])]
    public function apiSegmentOperators(string $type): Response
    {
        $operators = $this->segmentService->getOperatorsForFieldType($type);
        return $this->json($operators);
    }

    /**
     * A/B Test Configuration
     */
    #[Route('/{id}/ab-test', name: 'app_email_campaign_ab_test', methods: ['GET', 'POST'])]
    public function abTest(EmailCampaign $campaign, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $testType = $request->request->get('test_type');
            $testPercentage = (float) $request->request->get('test_percentage', 20);
            $testDuration = (int) $request->request->get('test_duration', 24);
            $variants = json_decode($request->request->get('variants', '[]'), true);

            try {
                $this->abTestService->createAbTest(
                    $campaign,
                    $testType,
                    $variants,
                    $testPercentage,
                    $testDuration
                );

                $this->addFlash('success', 'A/B test configured successfully!');
                return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $recommendations = $this->abTestService->getTestRecommendations($campaign);

        return $this->render('email_campaign/ab_test.html.twig', [
            'campaign' => $campaign,
            'recommendations' => $recommendations,
        ]);
    }

    /**
     * A/B Test Results
     */
    #[Route('/{id}/ab-test/results', name: 'app_email_campaign_ab_test_results', methods: ['GET'])]
    public function abTestResults(EmailCampaign $campaign): Response
    {
        $results = $this->abTestService->getTestResults($campaign);

        return $this->render('email_campaign/ab_test_results.html.twig', [
            'campaign' => $campaign,
            'results' => $results,
        ]);
    }

    /**
     * Declare A/B Test Winner
     */
    #[Route('/{id}/ab-test/declare-winner', name: 'app_email_campaign_ab_test_declare_winner', methods: ['POST'])]
    public function declareAbTestWinner(EmailCampaign $campaign, Request $request): Response
    {
        $metricType = $request->request->get('metric_type');

        try {
            $winningVariant = $this->abTestService->declareWinner($campaign, $metricType);
            $this->addFlash('success', "Variant $winningVariant declared as winner!");
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_email_campaign_ab_test_results', ['id' => $campaign->getId()]);
    }

    /**
     * Create Drip Campaign
     */
    #[Route('/drip/create', name: 'app_email_campaign_drip_create', methods: ['GET', 'POST'])]
    public function createDripCampaign(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $name = $request->request->get('name');
            $description = $request->request->get('description');
            $sequence = json_decode($request->request->get('sequence', '[]'), true);
            $segmentId = $request->request->get('segment_id');

            $segment = null;
            if ($segmentId) {
                $segment = $this->entityManager->getRepository(EmailSegment::class)->find($segmentId);
            }

            try {
                $campaign = $this->dripCampaignService->createDripCampaign(
                    $name,
                    $description,
                    $sequence,
                    $segment
                );

                $this->addFlash('success', 'Drip campaign created successfully!');
                return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $segments = $this->entityManager->getRepository(EmailSegment::class)
            ->findBy(['isActive' => true], ['name' => 'ASC']);

        $templates = $this->templateService->getActiveTemplates();

        return $this->render('email_campaign/drip_create.html.twig', [
            'segments' => $segments,
            'templates' => $templates,
        ]);
    }

    /**
     * Drip Campaign Dashboard
     */
    #[Route('/{id}/drip', name: 'app_email_campaign_drip_dashboard', methods: ['GET'])]
    public function dripDashboard(EmailCampaign $campaign): Response
    {
        $analytics = $this->dripCampaignService->getDripAnalytics($campaign);
        $enrolledContacts = $this->dripCampaignService->getEnrolledContacts($campaign);

        return $this->render('email_campaign/drip_dashboard.html.twig', [
            'campaign' => $campaign,
            'analytics' => $analytics,
            'enrolledContacts' => $enrolledContacts,
        ]);
    }

    /**
     * Enroll Contact in Drip Campaign
     */
    #[Route('/{id}/drip/enroll', name: 'app_email_campaign_drip_enroll', methods: ['POST'])]
    public function enrollInDrip(EmailCampaign $campaign, Request $request): Response
    {
        $contactId = $request->request->get('contact_id');
        $contact = $this->contactRepository->find($contactId);

        if (!$contact) {
            $this->addFlash('error', 'Contact not found.');
            return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
        }

        try {
            $result = $this->dripCampaignService->enrollContact($campaign, $contact);
            if ($result['success']) {
                $this->addFlash('success', $result['message']);
            } else {
                $this->addFlash('warning', $result['message']);
            }
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
    }

    /**
     * Unenroll Contact from Drip Campaign
     */
    #[Route('/{id}/drip/unenroll/{contactId}', name: 'app_email_campaign_drip_unenroll', methods: ['POST'])]
    public function unenrollFromDrip(EmailCampaign $campaign, int $contactId): Response
    {
        $contact = $this->contactRepository->find($contactId);

        if (!$contact) {
            $this->addFlash('error', 'Contact not found.');
            return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
        }

        try {
            $this->dripCampaignService->unenrollContact($campaign, $contact);
            $this->addFlash('success', 'Contact unenrolled successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
    }

    /**
     * Pause Drip Campaign
     */
    #[Route('/{id}/drip/pause', name: 'app_email_campaign_drip_pause', methods: ['POST'])]
    public function pauseDrip(EmailCampaign $campaign): Response
    {
        $this->dripCampaignService->pauseDripCampaign($campaign);
        $this->addFlash('success', 'Drip campaign paused.');
        return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
    }

    /**
     * Resume Drip Campaign
     */
    #[Route('/{id}/drip/resume', name: 'app_email_campaign_drip_resume', methods: ['POST'])]
    public function resumeDrip(EmailCampaign $campaign): Response
    {
        $this->dripCampaignService->resumeDripCampaign($campaign);
        $this->addFlash('success', 'Drip campaign resumed.');
        return $this->redirectToRoute('app_email_campaign_drip_dashboard', ['id' => $campaign->getId()]);
    }
}
