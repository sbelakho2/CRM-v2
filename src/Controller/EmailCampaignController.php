<?php

namespace App\Controller;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Form\EmailCampaignType;
use App\Repository\EmailCampaignRepository;
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
        private TranslatorInterface $translator
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

    #[Route('/{id}', name: 'app_email_campaign_show', methods: ['GET'])]
    public function show(EmailCampaign $campaign): Response
    {
        $metrics = $this->campaignService->getCampaignMetrics($campaign);
        
        // Get sends grouped by touch number
        $sendsByTouch = [];
        foreach ($campaign->getEmailSends() as $send) {
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

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.updated'));
            return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
        }

        return $this->render('email_campaign/edit.html.twig', [
            'campaign' => $campaign,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_email_campaign_delete', methods: ['POST'])]
    public function delete(Request $request, EmailCampaign $campaign): Response
    {
        if ($this->isCsrfTokenValid('delete'.$campaign->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($campaign);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.deleted'));
        }

        return $this->redirectToRoute('app_email_campaign_index');
    }

    #[Route('/{id}/toggle-active', name: 'app_email_campaign_toggle_active', methods: ['POST'])]
    public function toggleActive(Request $request, EmailCampaign $campaign): Response
    {
        if ($this->isCsrfTokenValid('toggle'.$campaign->getId(), $request->request->get('_token'))) {
            $campaign->setActive(!$campaign->isActive());
            $this->entityManager->flush();

            $statusTrans = $campaign->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', $this->translator->trans('email_campaign.flash.' . $statusTrans));
        }

        return $this->redirectToRoute('app_email_campaign_show', ['id' => $campaign->getId()]);
    }

    #[Route('/{id}/send', name: 'app_email_campaign_send', methods: ['GET', 'POST'])]
    public function send(Request $request, EmailCampaign $campaign): Response
    {
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

            $sentCount = 0;
            foreach ($contactIds as $contactId) {
                $contact = $this->contactRepository->find($contactId);
                if ($contact) {
                    $this->campaignService->sendToContact($campaign, $contact, $touchNumber);
                    $sentCount++;
                }
            }

            // Provide guidance after sending campaign
            $this->guidanceService->afterEmailCampaignSent($campaign->getId(), $sentCount);

            $this->addFlash('success', $this->translator->trans('email_campaign.flash.sent_count', ['%count%' => $sentCount]));
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
    public function trackOpen(Request $request, EmailSend $send): Response
    {
        $sig = $request->query->get('sig');

        if (!$send->isOpened() && $send->getId() && $this->trackingSigner->verifyOpen($send->getId(), $sig)) {
            $this->campaignService->markOpened($send);
        }

        // Return 1x1 transparent pixel
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        return new Response($pixel, 200, ['Content-Type' => 'image/gif']);
    }

    #[Route('/track/{id}/click', name: 'app_email_send_track_click', methods: ['GET'])]
    public function trackClick(Request $request, EmailSend $send): Response
    {
        // Redirect to the actual URL
        $url = (string)$request->query->get('url', '/');
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
