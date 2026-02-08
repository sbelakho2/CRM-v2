<?php

namespace App\Tests\Unit\Service;

use App\Service\EmailClassifierService;
use App\Service\ThompsonSamplerService;
use App\Repository\InboxMessageRepository;
use App\Repository\ContactRepository;
use App\Repository\BayesTrainingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class EmailClassifierServiceTest extends TestCase
{
    private EmailClassifierService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $inboxRepo = $this->createMock(InboxMessageRepository::class);
        $contactRepo = $this->createMock(ContactRepository::class);
        $thompsonSampler = $this->createMock(ThompsonSamplerService::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new EmailClassifierService(
            $em,
            $inboxRepo,
            $contactRepo,
            $thompsonSampler,
            null, // No BayesTrainingRepository — tests fallback model
            $logger
        );
    }

    public function testClassifyEmailPositiveReply(): void
    {
        $result = $this->service->classifyEmail(
            'Re: Product Inquiry',
            'Thank you for reaching out! I am very interested in your product. Can we schedule a meeting to discuss pricing?',
            'buyer@example.com'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('classification', $result);
        $this->assertArrayHasKey('confidence', $result);
        
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThan(0, $result['confidence']);
    }

    public function testClassifyEmailNegativeReply(): void
    {
        $result = $this->service->classifyEmail(
            'Re: Offer',
            'Please remove me from your mailing list. I am not interested and do not want to receive any more emails.',
            'unhappy@example.com'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('classification', $result);
    }

    public function testClassifyEmailOutOfOffice(): void
    {
        $result = $this->service->classifyEmail(
            'Out of Office AutoReply',
            'I am currently out of the office with no access to email. I will return on Monday. For urgent matters, please contact my colleague.',
            'away@example.com'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('classification', $result);
    }

    public function testClassifyEmailBounce(): void
    {
        $result = $this->service->classifyEmail(
            'Mail Delivery Subsystem',
            'Delivery to the following recipient failed permanently: user@unknown.com. The email account that you tried to reach does not exist.',
            'mailer-daemon@google.com'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('classification', $result);
    }

    public function testGetBayesStatisticsWithoutRepository(): void
    {
        $stats = $this->service->getBayesStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('status', $stats);
        $this->assertArrayHasKey('totalWords', $stats);
        $this->assertArrayHasKey('trainingExamples', $stats);
        $this->assertArrayHasKey('byClassification', $stats);
        $this->assertEquals('in-memory-only', $stats['status']);
        $this->assertGreaterThan(0, $stats['totalWords']);
    }

    public function testClassifyEmptyContent(): void
    {
        $result = $this->service->classifyEmail('', '', 'unknown@example.com');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('classification', $result);
    }
}
