<?php

namespace App\Tests\Unit\Service;

use App\Entity\EmailUnsubscribe;
use App\Service\EmailDeliverabilityService;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailDeliverabilityServiceTest extends TestCase
{
    public function testProcessBounceHardAddsToSuppressionList(): void
    {
        $email = 'user@example.com';

        $send = $this->getMockBuilder(\App\Entity\EmailSend::class)
            ->addMethods(['getEmailAddress','setStatus','setFailureReason','getRetryCount','setRetryCount'])
            ->onlyMethods(['setBounced','getContact'])
            ->getMock();

        $send->expects($this->once())->method('getEmailAddress')->willReturn($email);
        $send->expects($this->once())->method('setBounced')->with(true);
        $send->expects($this->once())->method('setStatus')->with('failed');
        $send->expects($this->once())->method('setFailureReason')->with('reason');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(EmailUnsubscribe::class));
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new EmailDeliverabilityService($em);

        $service->processBounce($send, 'hard', 'reason');
    }

    public function testProcessBounceSoftIncrementRetryAndSuppressAfterLimit(): void
    {
        $email = 'soft@example.com';

        $send = $this->getMockBuilder(\App\Entity\EmailSend::class)
            ->addMethods(['getEmailAddress','setStatus','setFailureReason','getRetryCount','setRetryCount'])
            ->onlyMethods(['setBounced','getContact'])
            ->getMock();

        $send->method('getEmailAddress')->willReturn($email);
        $send->method('getRetryCount')->willReturn(2);
        $send->expects($this->once())->method('setRetryCount')->with(3);
        $send->expects($this->once())->method('setBounced')->with(true);
        $send->expects($this->once())->method('setStatus')->with('failed');
        $send->expects($this->once())->method('setFailureReason');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(EmailUnsubscribe::class));
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new EmailDeliverabilityService($em);

        $service->processBounce($send, 'soft', 'soft reason');
    }

    public function testProcessComplaintAddsSuppressionAndUnsubscribesContact(): void
    {
        $email = 'complaint@example.com';

        $contact = $this->getMockBuilder(\App\Entity\Contact::class)
            ->addMethods(['setSubscribed'])
            ->getMock();

        $send = $this->getMockBuilder(\App\Entity\EmailSend::class)
            ->addMethods(['getEmailAddress','setStatus','setFailureReason'])
            ->onlyMethods(['getContact'])
            ->getMock();

        $send->method('getEmailAddress')->willReturn($email);
        $send->method('getContact')->willReturn($contact);
        $send->expects($this->once())->method('setStatus')->with('failed');
        $send->expects($this->once())->method('setFailureReason');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(EmailUnsubscribe::class));
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new EmailDeliverabilityService($em);

        $service->processComplaint($send, 'abusive');
    }
}

