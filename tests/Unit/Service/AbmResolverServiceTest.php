<?php

namespace App\Tests\Unit\Service;

use App\Entity\AbmAccount;
use App\Entity\AbmHit;
use App\Entity\IpMap;
use App\Repository\AbmAccountRepository;
use App\Repository\AbmHitRepository;
use App\Repository\IpMapRepository;
use App\Repository\WebEventRepository;
use App\Service\AbmResolverService;
use App\Service\PlaybookEngine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AbmResolverServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private WebEventRepository $webEventRepo;
    private AbmHitRepository $abmHitRepo;
    private AbmAccountRepository $abmAccountRepo;
    private IpMapRepository $ipMapRepo;
    private PlaybookEngine $playbookEngine;
    private AbmResolverService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->webEventRepo = $this->createMock(WebEventRepository::class);
        $this->abmHitRepo = $this->createMock(AbmHitRepository::class);
        $this->abmAccountRepo = $this->createMock(AbmAccountRepository::class);
        $this->ipMapRepo = $this->createMock(IpMapRepository::class);
        $this->playbookEngine = $this->createMock(PlaybookEngine::class);

        $this->service = new AbmResolverService(
            $this->entityManager,
            $this->webEventRepo,
            $this->abmHitRepo,
            $this->abmAccountRepo,
            $this->ipMapRepo,
            $this->playbookEngine
        );
    }

    public function testResolveIpReturnsCachedOrganization(): void
    {
        $ip = '8.8.8.8';

        $ipMap = new IpMap();
        $ipMap->setIpAddress($ip);
        $ipMap->setOrganizationName('Example Corp');
        $ipMap->setCountry('MA');
        $ipMap->setCity('Tanger');
        $ipMap->setAsof(new \DateTime());
        $ipMap->setExpiresAt(new \DateTime('+1 day'));

        $this->ipMapRepo->method('findOneBy')->willReturn($ipMap);

        $result = $this->service->resolveIp($ip);

        $this->assertTrue($result['resolved']);
        $this->assertSame('Example Corp', $result['companyName']);
        $this->assertSame('MA', $result['country']);
        $this->assertSame('Tanger', $result['city']);
    }

    public function testProcessWebEventReturnsExpectedStructure(): void
    {
        $eventData = [
            'ip' => '8.8.8.8',
            'url' => '/product/enterprise',
            'userAgent' => 'Mozilla/5.0',
            'timestamp' => '2024-01-15 10:30:00',
        ];

        $ipMap = new IpMap();
        $ipMap->setIpAddress('8.8.8.8');
        $ipMap->setOrganizationName('Acme');
        $ipMap->setAsof(new \DateTime());
        $ipMap->setExpiresAt(new \DateTime('+1 day'));
        $this->ipMapRepo->method('findOneBy')->willReturn($ipMap);

        $account = new AbmAccount();
        $account->setAccountName('Acme');
        $account->setDomain('acme.com');

        $this->abmAccountRepo->method('findOneBy')->willReturn($account);
        $this->playbookEngine->method('evaluatePlaybooks')->willReturn(false);

        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->atLeastOnce())->method('flush');

        $result = $this->service->processWebEvent($eventData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('resolved', $result);
        $this->assertArrayHasKey('companyName', $result);
        $this->assertArrayHasKey('abmHitId', $result);
        $this->assertArrayHasKey('playbookTriggered', $result);
    }

    public function testGetRecentHitsReturnsEmptyWhenAccountMissing(): void
    {
        $this->abmAccountRepo->method('find')->willReturn(null);
        $this->assertSame([], $this->service->getRecentHits(123));
    }

    public function testGetAccountStatsReturnsZerosWhenAccountMissing(): void
    {
        $this->abmAccountRepo->method('find')->willReturn(null);

        $stats = $this->service->getAccountStats(123);

        $this->assertSame(0, $stats['totalHits']);
        $this->assertSame(0, $stats['pageviews']);
        $this->assertSame(0, $stats['uniqueVisitors']);
        $this->assertNull($stats['lastHitAt']);
    }

    public function testImportTargetAccountsImportsNewAccounts(): void
    {
        $csvPath = sys_get_temp_dir() . '/abm_accounts_test.csv';
        file_put_contents($csvPath, "company_name,domain,tier\nAcme,acme.com,A\n");

        $this->abmAccountRepo->method('findOneBy')->willReturn(null);
        $this->entityManager->expects($this->atLeastOnce())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $count = $this->service->importTargetAccounts($csvPath);

        $this->assertSame(1, $count);
        @unlink($csvPath);
    }
}
