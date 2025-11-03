<?php

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use Doctrine\ORM\EntityManagerInterface;

class EmailCampaignService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private EmailSendRepository $sendRepository
    ) {}

    /**
     * Create a new email campaign
     */
    public function createCampaign(string $name, string $language, int $touchCount = 5): EmailCampaign
    {
        $campaign = new EmailCampaign();
        $campaign->setName($name);
        $campaign->setLanguage($language);
        $campaign->setTouchCount($touchCount);
        $campaign->setActive(true);

        $this->entityManager->persist($campaign);
        $this->entityManager->flush();

        return $campaign;
    }

    /**
     * Send email to contact as part of campaign
     */
    public function sendToContact(EmailCampaign $campaign, Contact $contact, int $touchNumber): EmailSend
    {
        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber($touchNumber);
        $send->setSentAt(new \DateTime());

        $this->entityManager->persist($send);
        $this->entityManager->flush();

        return $send;
    }

    /**
     * Mark email as opened
     */
    public function markOpened(EmailSend $send): void
    {
        $send->setOpened(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as clicked
     */
    public function markClicked(EmailSend $send): void
    {
        $send->setClicked(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as replied
     */
    public function markReplied(EmailSend $send): void
    {
        $send->setReplied(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as bounced
     */
    public function markBounced(EmailSend $send): void
    {
        $send->setBounced(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Get campaign performance metrics
     */
    public function getCampaignMetrics(EmailCampaign $campaign): array
    {
        $sends = $campaign->getSends();
        $total = count($sends);
        
        if ($total === 0) {
            return [
                'total_sent' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'reply_rate' => 0,
                'bounce_rate' => 0,
            ];
        }

        $opened = 0;
        $clicked = 0;
        $replied = 0;
        $bounced = 0;

        foreach ($sends as $send) {
            if ($send->isOpened()) $opened++;
            if ($send->isClicked()) $clicked++;
            if ($send->isReplied()) $replied++;
            if ($send->isBounced()) $bounced++;
        }

        return [
            'total_sent' => $total,
            'opened' => $opened,
            'clicked' => $clicked,
            'replied' => $replied,
            'bounced' => $bounced,
            'open_rate' => ($opened / $total) * 100,
            'click_rate' => ($clicked / $total) * 100,
            'reply_rate' => ($replied / $total) * 100,
            'bounce_rate' => ($bounced / $total) * 100,
        ];
    }

    /**
     * Get contacts for next touch in sequence
     */
    public function getContactsForNextTouch(EmailCampaign $campaign, int $touchNumber): array
    {
        // Logic to find contacts who need the next touch
        // This would check who received touch N-1 and needs touch N
        return []; // To be implemented with complex query
    }

    /**
     * Get 5-touch sequence progress for contact
     */
    public function getContactProgress(Contact $contact, EmailCampaign $campaign): array
    {
        $touches = [1 => false, 2 => false, 3 => false, 4 => false, 5 => false];
        
        $qb = $this->entityManager->createQueryBuilder();
        $sends = $qb->select('s')
            ->from(EmailSend::class, 's')
            ->where('s.campaign = :campaign')
            ->andWhere('s.contact = :contact')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->getQuery()
            ->getResult();

        foreach ($sends as $send) {
            $touchNum = $send->getTouchNumber();
            if ($touchNum >= 1 && $touchNum <= 5) {
                $touches[$touchNum] = [
                    'sent' => $send->getSentAt(),
                    'opened' => $send->isOpened(),
                    'clicked' => $send->isClicked(),
                    'replied' => $send->isReplied(),
                ];
            }
        }

        return $touches;
    }
}
