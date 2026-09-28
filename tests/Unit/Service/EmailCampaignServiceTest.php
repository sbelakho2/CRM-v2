<?php

namespace App\Tests\Unit\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Service\EmailCampaignService;
use App\Service\EmailTrackingSigner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailCampaignServiceTest extends TestCase
{
    public function testGetCampaignMetricsWithNoSends(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $sendRepo = $this->createMock(\App\Repository\EmailSendRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $trackingSigner = new EmailTrackingSigner('test-secret');
        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailCampaignService($this->createMock(\Doctrine\Persistence\ManagerRegistry::class), $em, $campaignRepo, $sendRepo, $mailer, $urlGenerator, $trackingSigner, $logger, new \App\Service\EmailSendPolicy($em), $this->createMock(\Symfony\Component\Mailer\Transport\TransportInterface::class));

        $campaign = new EmailCampaign();

        $metrics = $service->getCampaignMetrics($campaign);

        $this->assertEquals(0, $metrics['total_sent']);
        $this->assertEquals(0, $metrics['opened']);
        $this->assertEquals(0, $metrics['clicked']);
        $this->assertEquals(0, $metrics['replied']);
        $this->assertEquals(0, $metrics['bounced']);
    }

    public function testGetCampaignMetricsWithSends(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $sendRepo = $this->createMock(\App\Repository\EmailSendRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $trackingSigner = new EmailTrackingSigner('test-secret');
        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailCampaignService($this->createMock(\Doctrine\Persistence\ManagerRegistry::class), $em, $campaignRepo, $sendRepo, $mailer, $urlGenerator, $trackingSigner, $logger, new \App\Service\EmailSendPolicy($em), $this->createMock(\Symfony\Component\Mailer\Transport\TransportInterface::class));

        $campaign = new EmailCampaign();

        $s1 = new EmailSend();
        $s1->setStatus(EmailSend::STATUS_SENT);
        $s1->setOpened(true);

        $s2 = new EmailSend();
        $s2->setStatus(EmailSend::STATUS_SENT);
        $s2->setOpened(true);
        $s2->setClicked(true);

        $campaign->addEmailSend($s1);
        $campaign->addEmailSend($s2);

        $metrics = $service->getCampaignMetrics($campaign);

        $this->assertEquals(2, $metrics['total_sent']);
        $this->assertEquals(2, $metrics['opened']);
        $this->assertEquals(1, $metrics['clicked']);
    }

    public function testMarkMethodsPersistAndSetFlags(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(4))->method('persist');
        $em->expects($this->exactly(4))->method('flush');

        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $sendRepo = $this->createMock(\App\Repository\EmailSendRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $trackingSigner = new EmailTrackingSigner('test-secret');
        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailCampaignService($this->createMock(\Doctrine\Persistence\ManagerRegistry::class), $em, $campaignRepo, $sendRepo, $mailer, $urlGenerator, $trackingSigner, $logger, new \App\Service\EmailSendPolicy($em), $this->createMock(\Symfony\Component\Mailer\Transport\TransportInterface::class));

        $send = new EmailSend();

        $service->markOpened($send);
        $this->assertTrue($send->isOpened());

        $service->markClicked($send);
        $this->assertTrue($send->isClicked());

        $service->markReplied($send);
        $this->assertTrue($send->isReplied());

        $service->markBounced($send);
        $this->assertTrue($send->isBounced());
    }

    public function testGetContactProgressReturnsTouches(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $sendRepo = $this->createMock(\App\Repository\EmailSendRepository::class);
        $mailer = $this->createMock(MailerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $trackingSigner = new EmailTrackingSigner('test-secret');
        $logger = $this->createMock(LoggerInterface::class);

        $service = new EmailCampaignService($this->createMock(\Doctrine\Persistence\ManagerRegistry::class), $em, $campaignRepo, $sendRepo, $mailer, $urlGenerator, $trackingSigner, $logger, new \App\Service\EmailSendPolicy($em), $this->createMock(\Symfony\Component\Mailer\Transport\TransportInterface::class));

        $contact = new Contact();
        $campaign = new EmailCampaign();

        $s1 = new EmailSend();
        $s1->setTouchNumber(1);
        $s1->setOpened(true);

        $s2 = new EmailSend();
        $s2->setTouchNumber(3);
        $s2->setClicked(true);

        $results = [$s1, $s2];

        $qb = new class($results) {
            private $results;
            public function __construct($results) { $this->results = $results; }
            public function select($a) { return $this; }
            public function from($a,$b) { return $this; }
            public function where($a) { return $this; }
            public function andWhere($a) { return $this; }
            public function setParameter($k,$v) { return $this; }
            public function getQuery() { $res = $this->results; return new class($res) { private $r; public function __construct($r){$this->r=$r;} public function getResult(){return $this->r;} }; }
        };

        $em->method('createQueryBuilder')->willReturn($qb);

        $progress = $service->getContactProgress($contact, $campaign);

        $this->assertArrayHasKey(1, $progress);
        $this->assertArrayHasKey(3, $progress);
        $this->assertIsArray($progress[1]);
        $this->assertIsArray($progress[3]);
        $this->assertTrue($progress[1]['opened']);
        $this->assertTrue($progress[3]['clicked']);
    }
}