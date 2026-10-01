<?php

namespace App\Controller;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Form\EmailCampaignType;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use App\Repository\ContactRepository;
use App\Service\EmailCampaignService;
use App\Service\EmailTrackingSigner;
use App\Service\GuidanceNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/email-campaigns')]
class EmailCampaignController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private ContactRepository $contactRepository,
        private EmailCampaignService $campaignService,
        private EmailTrackingSigner $trackingSigner,
        private GuidanceNotificationService $guidanceService,
        private TranslatorInterface $translator,
        private EmailSendRepository $emailSendRepository
    ) {}
    #[Route('/', name: 'app_email_campaign_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status', 'all');
        $language = $request->query->get('language', 'all');

        $qb = $this->campaignRepository->createQueryBuilder('c')
            ->andWhere('c.archivedAt IS NULL')
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

        // Batch metrics for all campaigns in a single aggregate query
        // (replaces N per-campaign getCampaignMetrics() queries)
        $campaignMetrics = $this->campaignRepository->findWithSendCounts(
            array_map(static fn(EmailCampaign $campaign) => (int) $campaign->getId(), $campaigns)
        );

        return $this->render('email_campaign/index.html.twig', [
            'campaigns' => $campaigns,
            'campaignMetrics' => $campaignMetrics,
            'currentStatus' => $status,
            'currentLanguage' => $language,
        ]);
    }

    #[Route('/new', name: 'app_email_campaign_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $campaign = new EmailCampaign();
        $form = $this->createForm(EmailCampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($campaign);
            $this->entityManager->flush();

            // Provide guidance for campaign workflow
            $this->guidanceService->afterEmailCampaignCreated(
                $campaign->getId(),
                $campaign->getName()
            );

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.created'));
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('email_campaign/new.html.twig', [
            'campaign' => $campaign,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_email_campaign_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(EmailCampaign $campaign): Response
    {
        if ($campaign->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Campaign not found');
        }

        $metrics = $this->campaignService->getCampaignMetrics($campaign);
        
        // Get sends grouped by touch number (recipient contacts joined in
        // one query instead of lazy-loading each send's contact)
        $sendsByTouch = [];
        foreach ($this->emailSendRepository->findByCampaignWithContact($campaign->getId()) as $send) {
            $touchNum = $send->getTouchNumber();
            if (!isset($sendsByTouch[$touchNum])) {
                $sendsByTouch[$touchNum] = [];
            }
            $sendsByTouch[$touchNum][] = $send;
        }

        // Calculate per-touch metrics
        $touchMetrics = [];
        for ($i = 1; $i <= $campaign->getTouchCount(); $i++) {
            $sends = array_values(array_filter(
                $sendsByTouch[$i] ?? [],
                static fn (\App\Entity\EmailSend $s) => in_array(
                    $s->getStatus(),
                    [\App\Entity\EmailSend::STATUS_SENT, \App\Entity\EmailSend::STATUS_BOUNCED],
                    true
                )
            ));
            // Engagement denominators describe the DELIVERED population,
            // consistent with getCampaignMetrics().
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
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EmailCampaign $campaign): Response
    {
        if ($campaign->isArchived()) {
            $this->addFlash('error', 'This campaign is archived and read-only.');

            return $this->redirectToRoute('app_email_campaign_index');
        }

        $form = $this->createForm(EmailCampaignType::class, $campaign);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.updated'));
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('email_campaign/edit.html.twig', [
            'campaign' => $campaign,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_email_campaign_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, EmailCampaign $campaign): Response
    {
        if ($this->isCsrfTokenValid('delete'.$campaign->getId(), $request->request->get('_token'))) {
            // Campaigns carry send/open/click/reply/bounce history: they are
            // archived, never hard-deleted.
            $campaign->archive($this->getUser(), 'Archived from campaigns list');
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.deleted'));
        }

        return $this->redirectToRoute('app_email_campaign_index');
    }

    #[Route('/{id}/toggle-active', name: 'app_email_campaign_toggle_active', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleActive(Request $request, EmailCampaign $campaign): Response
    {
        // Toggle must never resurrect an archived campaign into the
        // contradictory active+archived state.
        if ($campaign->isArchived()) {
            $this->addFlash('error', 'This campaign is archived and cannot be reactivated.');

            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        if ($this->isCsrfTokenValid('toggle'.$campaign->getId(), $request->request->get('_token'))) {
            // Transition methods enforce lifecycle invariants (archived is
            // never re-activatable; pause/activate keep status coherent).
            $campaign->isActive() ? $campaign->pause() : $campaign->activate();
            $this->entityManager->flush();

            $statusTrans = $campaign->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', $this->translator->trans('email_campaign.flash.' . $statusTrans));
        }

        return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
    }

    #[Route('/{id}/send', name: 'app_email_campaign_send', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function send(Request $request, EmailCampaign $campaign): Response
    {
        if ($campaign->isArchived()) {
            $this->addFlash('error', 'This campaign is archived; sending is disabled.');

            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        if ($request->isMethod('POST')) {
            // CSRF validation for mass email send
            $csrfToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('campaign_send' . $campaign->getId(), $csrfToken)) {
                throw $this->createAccessDeniedException('Invalid CSRF token');
            }

            // Handle both form-encoded and JSON payloads
            $contentType = $request->headers->get('Content-Type', '');
            if (str_contains($contentType, 'application/json')) {
                $data = json_decode($request->getContent(), true) ?? [];
                $contactIds = $data['contacts'] ?? [];
                $touchNumber = (int) ($data['touch_number'] ?? 1);
            } else {
                $contactIds = $request->request->all('contacts');
                $touchNumber = (int) $request->request->get('touch_number', 1);
            }

            if (empty($contactIds)) {
                $this->addFlash('error', $this->translator->trans('email_campaign.flash.select_contacts'));
                return $this->redirectToRoute('app_email_campaign_send', ['id' => $campaign->getId()]);
            }

            // SMTP handoffs belong to workers, never to web requests: the
            // request only creates canonical QUEUED send rows; the
            // due-send worker delivers them synchronously through the same
            // state machine (policy, leases, transport).
            $sentCount = 0;
            $skippedCount = 0;
            $failedCount = 0;
            foreach ($contactIds as $contactId) {
                $contact = $this->contactRepository->find($contactId);
                if ($contact) {
                    $result = $this->campaignService->queueTouch($campaign, $contact, $touchNumber);
                    if ($result->outcome === \App\Service\CampaignSendResult::QUEUED) {
                        $sentCount++; // QUEUED for worker delivery
                    } elseif ($result->outcome === \App\Service\CampaignSendResult::FAILED) {
                        $failedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            }

            // Provide guidance after sending campaign
            $this->guidanceService->afterEmailCampaignSent($campaign->getId(), $sentCount);

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.sent_count', ['%count%' => $sentCount]));
            if ($skippedCount > 0 || $failedCount > 0) {
                $this->addFlash('warning', sprintf(
                    '%d skipped by send policy (unsubscribed/bounce/cadence), %d failed and can be retried.',
                    $skippedCount,
                    $failedCount
                ));
            }
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        // Get all contacts with company eager loaded (optimized)
        $contacts = $this->contactRepository->findAllWithCompany();

        return $this->render('email_campaign/send.html.twig', [
            'campaign' => $campaign,
            'contacts' => $contacts,
        ]);
    }

    #[Route('/{id}/analytics', name: 'app_email_campaign_analytics', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function analytics(EmailCampaign $campaign): Response
    {
        if ($campaign->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException('Campaign not found');
        }

        $metrics = $this->campaignService->getCampaignMetrics($campaign);
        
        // Get timeline data (sends over time) — using native SQL for DATE()
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT DATE(s.sent_at) AS date, COUNT(s.id) AS count
                FROM email_sends s
                WHERE s.campaign_id = :campaignId
                GROUP BY date
                ORDER BY date ASC';
        /** @var array<int, array<string, mixed>> $sendsByDate */
        $sendsByDate = $conn->fetchAllAssociative($sql, [
            'campaignId' => $campaign->getId(),
        ]);

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
    #[IsGranted('PUBLIC_ACCESS')]
    public function trackOpen(Request $request, EmailSend $send): Response
    {
        /** @var string|int|float|bool|null $sig */
        $sig = $request->query->get('sig');

        if (!$send->isOpened() && $send->getId() && $this->trackingSigner->verifyOpen($send->getId(), $sig)) {
            $this->campaignService->markOpened($send);
        }

        // Return 1x1 transparent pixel
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return new Response($pixel, 200, ['Content-Type' => 'image/gif']);
    }

    #[Route('/track/{id}/click', name: 'app_email_send_track_click', methods: ['GET'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function trackClick(Request $request, EmailSend $send): Response
    {
        // Redirect to the actual URL
        $url = (string)$request->query->get('url', '/');
        /** @var string|int|float|bool|null $sig */
        $sig = $request->query->get('sig');

        $isSigned = $send->getId() && $this->trackingSigner->verifyClick($send->getId(), $url, $sig);

        // Without a valid signature, allow only relative URLs or same-host absolute URLs.
        // This prevents using this endpoint as an open redirect.
        if (!$isSigned) {
            $parsed = parse_url($url);
            $hasScheme = isset($parsed['scheme']);
            $hasHost = isset($parsed['host']);

            if ($hasScheme || $hasHost) {
                $scheme = $parsed['scheme'] ?? null;
                $host = $parsed['host'] ?? null;

                $requestHost = $request->getHost();

                $isSameHostHttp = in_array($scheme, ['http', 'https'], true) && $host === $requestHost;
                if (!$isSameHostHttp) {
                    $url = '/';
                }
            } else {
                // Must be a safe absolute-path reference.
                if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
                    $url = '/';
                }
            }
        }

        if (!$send->isClicked() && ($isSigned || $url !== '/')) {
            $this->campaignService->markClicked($send);
        }

        return $this->redirect($url);
    }

    #[Route('/send/{id}/mark-replied', name: 'app_email_send_mark_replied', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function markReplied(EmailSend $send, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mark_replied' . $send->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $this->campaignService->markReplied($send);
        
        $this->addFlash('success', $this->translator->trans('email_campaign.flash.replied'));
        
        // Redirect back to campaign show page
        return $this->redirectToRoute('app_email_campaign_show', ['id' => $send->getCampaign()->getId()]);
    }

    #[Route('/send/{id}/mark-bounced', name: 'app_email_send_mark_bounced', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function markBounced(EmailSend $send, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mark_bounced' . $send->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $this->campaignService->markBounced($send);
        
        $this->addFlash('success', $this->translator->trans('email_campaign.flash.bounced'));
        
        // Redirect back to campaign show page
        return $this->redirectToRoute('app_email_campaign_show', ['id' => $send->getCampaign()->getId()]);
    }
}
