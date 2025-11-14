<?php

namespace App\Tests\Unit\Service;

use App\Service\EmailSchedulerService;
use PHPUnit\Framework\TestCase;

class EmailSchedulerServiceTest extends TestCase
{
    public function testScheduleCampaignSetsStatusAndScheduledAt(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = new class extends \App\Entity\EmailCampaign {
            private string $status = 'draft';
            private bool $sendTimeOptimization = false;

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): self
            {
                $this->status = $s;
                return $this;
            }

            public function isSendTimeOptimization(): bool
            {
                return $this->sendTimeOptimization;
            }

            public function setUpdatedAt(\DateTimeImmutable $dt): self
            {
                // noop for test
                return $this;
            }
        };

        $em->expects($this->once())->method('flush');

        $service->scheduleCampaign($campaign, new \DateTimeImmutable('2030-01-01 10:00:00'), false);

        $this->assertEquals('scheduled', $campaign->getStatus());
        $this->assertInstanceOf(\DateTimeInterface::class, $campaign->getScheduledAt());
    }

    public function testScheduleCampaignOptimizeClearsScheduledAtWhenOptIn(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = new class extends \App\Entity\EmailCampaign {
            private string $status = 'draft';
            private bool $sendTimeOptimization = true;

            public function getStatus(): string
            {
                return $this->status;
            }

            public function setStatus(string $s): self
            {
                $this->status = $s;
                return $this;
            }

            public function isSendTimeOptimization(): bool
            {
                return $this->sendTimeOptimization;
            }

            public function setUpdatedAt(\DateTimeImmutable $dt): self
            {
                // noop for test
                return $this;
            }
        };

        $em->expects($this->once())->method('flush');

        $service->scheduleCampaign($campaign, null, true);

        $this->assertEquals('scheduled', $campaign->getStatus());
        $this->assertNull($campaign->getScheduledAt());
    }

    public function testProcessCampaignThrowsWhenNoSegment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign must have a segment');

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = $this->getMockBuilder(\App\Entity\EmailCampaign::class)
            ->addMethods(['getSegment'])
            ->getMock();

        $campaign->method('getSegment')->willReturn(null);

        $service->processCampaign($campaign);
    }

    public function testProcessCampaignWithNoContactsReturnsZeroAndUpdatesStatus(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $segment = $this->createMock(\App\Entity\EmailSegment::class);
        $segmentService->method('getSegmentContacts')->willReturn([]);

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = $this->getMockBuilder(\App\Entity\EmailCampaign::class)
            ->addMethods(['getSegment', 'setStatus', 'setSentAt'])
            ->getMock();

        $campaign->method('getSegment')->willReturn($segment);
        $campaign->expects($this->once())->method('setStatus')->with('sending');
        $campaign->expects($this->once())->method('setSentAt');

        $em->expects($this->once())->method('flush');

        $count = $service->processCampaign($campaign);

        $this->assertEquals(0, $count);
    }

    public function testCancelCampaignThrowsForInvalidStatus(): void
    {
        $this->expectException(\RuntimeException::class);

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = $this->getMockBuilder(\App\Entity\EmailCampaign::class)
            ->addMethods(['getStatus'])
            ->getMock();

        $campaign->method('getStatus')->willReturn('draft');

        $service->cancelCampaign($campaign);
    }

    public function testCancelCampaignUpdatesStatusAndExecutesQuery(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $messageBus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $segmentService = $this->createMock(\App\Service\EmailSegmentService::class);

        $query = $this->getMockBuilder(\stdClass::class)->addMethods(['setParameter', 'execute'])->getMock();
        $query->method('setParameter')->willReturnSelf();
        $query->expects($this->once())->method('execute')->willReturn(3);

        $em->expects($this->once())->method('createQuery')->willReturn($query);
        $em->expects($this->once())->method('flush');

        $service = new EmailSchedulerService($em, $messageBus, $segmentService);

        $campaign = $this->getMockBuilder(\App\Entity\EmailCampaign::class)
            ->addMethods(['getStatus', 'setStatus', 'setUpdatedAt'])
            ->getMock();

        $campaign->method('getStatus')->willReturn('scheduled');
        $campaign->expects($this->once())->method('setStatus')->with('cancelled');
        $campaign->expects($this->once())->method('setUpdatedAt');

        $service->cancelCampaign($campaign);
    }
}
