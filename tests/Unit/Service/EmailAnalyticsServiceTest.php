<?php

namespace App\Tests\Unit\Service;

use App\Service\EmailAnalyticsService;
use App\Entity\EmailCampaign;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailAnalyticsServiceTest extends TestCase
{
    public function testAnalyzeAbTestReturnsErrorWhenNoVariants(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $service = new EmailAnalyticsService($entityManager);

        $campaign = $this->createMock(EmailCampaign::class);
        $campaign->method('getAbTestVariants')->willReturn([]);

        $result = $service->analyzeAbTest($campaign);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testGetCampaignMetricsUsesEntityManagerQuery(): void
    {
        $expected = [
            'total' => '10',
            'sent' => '10',
            'opened' => '5',
            'clicked' => '2',
            'replied' => '1',
            'bounced' => '0',
        ];

        $queryStub = new class($expected) {
            private $result;
            public function __construct($result) { $this->result = $result; }
            public function setParameter($k, $v) { return $this; }
            public function getSingleResult() { return $this->result; }
            public function getResult() { return $this->result; }
        };

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('createQuery')
            ->willReturn($queryStub);

        $service = new EmailAnalyticsService($entityManager);

        $campaign = $this->createMock(EmailCampaign::class);

        $metrics = $service->getCampaignMetrics($campaign);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total', $metrics);
        $this->assertEquals(10, $metrics['total']);
        $this->assertEquals(10, $metrics['sent']);
        $this->assertEquals(5, $metrics['opened']);
        $this->assertEqualsWithDelta(50.0, $metrics['openRate'], 0.1);
    }
}
