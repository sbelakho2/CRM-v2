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

        // Test 1: Direct save via our EM
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
        $this->em->flush();
        $output->writeln('  ID: ' . $c1->getId());

        // Test 2: Save via discovery service
        $output->writeln("\n--- Test 2: Full saveDiscoveredCompanies path ---");
        $fakeData = [
            [
                'name' => 'T3_' . time(),
                'website' => 'https://t3-' . time() . '.example.com',
                'buyer_evidence' => ['verdict' => 'ACCEPT', 'reason' => 'Test', 'positive_families' => ['x' => 1]],
            ],
        ];
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod') !== 'test') {
            $output->writeln('Refusing to run: diagnostics that create/remove rows are only permitted with APP_ENV=test.');

            return Command::FAILURE;
        }

        $result = $this->discoveryService->saveDiscoveredCompanies($fakeData, 'Automotive', 'Tunis Tunisia');
        $output->writeln('  Returned: ' . count($result));

        // Track EXACTLY the rows this invocation created: cleaning by name
        // pattern would delete unrelated rows that happen to share a prefix.
        $createdIds = [];
        foreach ($result as $c) {
            $output->writeln('  Saved: ' . $c->getName() . ' (ID: ' . $c->getId() . ')');
            $createdIds[] = $c->getId();
        }

        $conn = $this->em->getConnection();
        if ($createdIds !== []) {
            $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT id, name FROM companies WHERE id IN ($placeholders) ORDER BY id",
                $createdIds
            );
        } else {
            $rows = [];
        }

        foreach ($rows as $r) {
            $output->writeln("  DB: id={$r['id']} name={$r['name']}");
        }

        // Cleanup: only the exact rows created above.
        foreach ($rows as $r) {
            $company = $this->em->getRepository(Company::class)->find($r['id']);
            if ($company) {
                $this->em->remove($company);
            }
        }
        $this->em->flush();
        $output->writeln('Cleaned up ' . count($rows) . ' test rows');
        $output->writeln('=== DONE ===');

        return Command::SUCCESS;
    }
}
