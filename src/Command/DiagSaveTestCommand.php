<?php

namespace App\Command;

use App\Entity\Company;
use App\Service\WebCrawler\CompanyDiscoveryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:diag-save-test', description: 'Diagnostic: test save path')]
class DiagSaveTestCommand extends Command
{
    public function __construct(
        private CompanyDiscoveryService $discoveryService,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('=== DIAGNOSTIC SAVE TEST v2 ===');

        // Check if $this->em and the EM used by discovery service are the same instance
        $ref = new \ReflectionProperty($this->discoveryService, 'em');
        $ref->setAccessible(true);
        $svcEm = $ref->getValue($this->discoveryService);
        
        $output->writeln('Command EM = ' . spl_object_id($this->em) . ' (' . get_class($this->em) . ')');
        $output->writeln('Service EM = ' . spl_object_id($svcEm) . ' (' . get_class($svcEm) . ')');
        $output->writeln('Same instance: ' . ($this->em === $svcEm ? 'YES' : 'NO!!!!'));

        // Check the repo too
        $repoRef = new \ReflectionProperty($this->discoveryService, 'companyRepo');
        $repoRef->setAccessible(true);
        $svcRepo = $repoRef->getValue($this->discoveryService);
        
        // Get the EM from the repo
        $repoEmRef = new \ReflectionMethod($svcRepo, 'getEntityManager');
        $repoEmRef->setAccessible(true);
        $repoEm = $repoEmRef->invoke($svcRepo);
        
        $output->writeln('Repo EM  = ' . spl_object_id($repoEm) . ' (' . get_class($repoEm) . ')');
        $output->writeln('Repo EM === Service EM: ' . ($repoEm === $svcEm ? 'YES' : 'NO!!!!'));

        // Test 1: Direct save via our EM (should work)
        $output->writeln("\n--- Test 1: Direct save via command EM ---");
        $c1 = new Company();
        $c1->setName('T1_' . time());
        $c1->setSector('Automotive');
        $c1->setPipelineStage('Prospect');
        $c1->setAccountTier('C');
        $c1->setCompanyStatus(Company::STATUS_DISCOVERED);
        $c1->setCreatedAt(new \DateTime());
        $c1->setUpdatedAt(new \DateTime());
        $this->em->persist($c1);
        $uow = $this->em->getUnitOfWork();
        $output->writeln('  Scheduled inserts (command EM): ' . count($uow->getScheduledEntityInsertions()));
        $this->em->flush();
        $output->writeln('  ID: ' . $c1->getId());

        // Test 2: Save via service EM
        $output->writeln("\n--- Test 2: Direct save via service EM ---");
        $c2 = new Company();
        $c2->setName('T2_' . time());
        $c2->setSector('Automotive');
        $c2->setPipelineStage('Prospect');
        $c2->setAccountTier('C');
        $c2->setCompanyStatus(Company::STATUS_DISCOVERED);
        $c2->setCreatedAt(new \DateTime());
        $c2->setUpdatedAt(new \DateTime());
        $svcEm->persist($c2);
        $uow2 = $svcEm->getUnitOfWork();
        $output->writeln('  Scheduled inserts (service EM): ' . count($uow2->getScheduledEntityInsertions()));
        $svcEm->flush();
        $output->writeln('  ID: ' . $c2->getId());

        // Test 3: Full saveDiscoveredCompanies path via reflection
        $output->writeln("\n--- Test 3: saveDiscoveredCompanies via reflection ---");
        $saveRef = new \ReflectionMethod($this->discoveryService, 'saveDiscoveredCompanies');
        $saveRef->setAccessible(true);
        
        $fakeData = [
            [
                'name' => 'T3_' . time(),
                'website' => 'https://t3-' . time() . '.example.com',
                'buyer_evidence' => ['verdict' => 'ACCEPT', 'reason' => 'Test', 'positive_families' => ['x' => 1]],
            ],
        ];
        $result = $saveRef->invoke($this->discoveryService, $fakeData, 'Automotive', 'Tunis Tunisia');
        $output->writeln('  Returned: ' . count($result));
        foreach ($result as $c) {
            $output->writeln('  Saved: ' . $c->getName() . ' (ID: ' . $c->getId() . ')');
        }

        // Verify all in DB
        $output->writeln("\n--- DB Verification ---");
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT id, name FROM companies WHERE name LIKE 'T1_%' OR name LIKE 'T2_%' OR name LIKE 'T3_%' ORDER BY id"
        );
        foreach ($rows as $r) {
            $output->writeln("  DB: id={$r['id']} name={$r['name']}");
        }

        // Cleanup
        foreach ($rows as $r) {
            $conn->executeStatement("DELETE FROM companies WHERE id = ?", [$r['id']]);
        }
        $output->writeln('Cleaned up ' . count($rows) . ' test rows');
        $output->writeln('=== DONE ===');

        return Command::SUCCESS;
    }
}
