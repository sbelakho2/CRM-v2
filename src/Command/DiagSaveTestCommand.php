<?php

namespace App\Command;

use App\Entity\Company;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Infrastructure\TestDatabaseGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:diag-save-test', description: 'Diagnostic: test save path (test environments only, rolls back everything)')]
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
        // ── Fail-closed guards: LITERALLY the first executable code. ──
        // Nothing above this point may write; without the guard first, a
        // production run would persist diagnostic rows BEFORE refusing.
        if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod') !== 'test') {
            $output->writeln('<error>Refusing to run outside APP_ENV=test.</error>');

            return Command::FAILURE;
        }

        TestDatabaseGuard::assertSafeTestDatabase($this->em->getConnection());

        $output->writeln('=== DIAGNOSTIC SAVE TEST v2 (transactional, always rolled back) ===');

        // The entire diagnostic runs inside one transaction that is ALWAYS
        // rolled back: cleanup correctness no longer depends on tracking
        // created rows (the previous approach leaked the direct-save T1_*
        // company because it was never added to the cleanup list).
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $stamp = time();
            $output->writeln("\n--- Test 1: Direct save via command EM ---");
            $c1 = new Company();
            $c1->setName('T1_' . $stamp);
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
                    'name' => 'T3_' . $stamp,
                    'website' => 'https://t3-' . $stamp . '.example.com',
                    'buyer_evidence' => ['verdict' => 'ACCEPT', 'reason' => 'Test', 'positive_families' => ['x' => 1]],
                ],
            ];

            $result = $this->discoveryService->saveDiscoveredCompanies($fakeData, 'Automotive', 'Tunis Tunisia');
            $output->writeln('  Returned: ' . count($result));
            foreach ($result as $c) {
                $output->writeln('  Saved: ' . $c->getName() . ' (ID: ' . $c->getId() . ')');
            }

            $output->writeln("\n--- DB Verification (inside transaction) ---");
            $stamp = time();
            // $stamp is the single immutable marker for this invocation.
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $connection->fetchAllAssociative(
                'SELECT id, name FROM companies WHERE name LIKE ? OR name LIKE ? ORDER BY id',
                ['T1_' . $stamp . '%', 'T3_' . $stamp . '%']
            );
            foreach ($rows as $r) {
                $output->writeln("  DB: id={$r['id']} name={$r['name']}");
            }
        } finally {
            // ALWAYS roll back — a diagnostic must never leave rows behind,
            // including when it fails halfway.
            $connection->rollBack();
        }

        $output->writeln("\nTransaction rolled back: no rows were persisted.");
        $output->writeln('=== DONE ===');

        return Command::SUCCESS;
    }
}
