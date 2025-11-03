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
        private EmailCampaignService $campaignService
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
}
