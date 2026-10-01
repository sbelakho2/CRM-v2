<?php

namespace App\Command;

use App\Entity\SupplierPortal;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

#[AsCommand(
    name: 'app:seed:tracker-data',
    description: 'Seed supplier portal data from Tracker.xlsx'
)]
class SeedTrackerDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to Tracker.xlsx', __DIR__ . '/../../Tracker.xlsx');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $trackerFile */
        $trackerFile = $input->getArgument('file');
        $projectDir = realpath(dirname(__DIR__, 2));

        if (!file_exists($trackerFile)) {
            $output->writeln('<error>Tracker.xlsx not found</error>');
            return Command::FAILURE;
        }

        $resolvedPath = realpath($trackerFile);
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $projectDir)) {
            $output->writeln('<error>File path is outside the project directory.</error>');
            return Command::FAILURE;
        }

        try {
            $this->entityManager->beginTransaction();

            $spreadsheet = IOFactory::load($trackerFile);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Skip header row
            $dataRows = array_slice($rows, 1);
            $count = 0;

            foreach ($dataRows as $row) {
                if (empty($row[0])) continue; // Skip empty rows

                $company = new Company();
                $company->setName($row[1] ?? 'Unknown');
                $company->setWebsite($row[3] ?? null);
                $company->setRegion($row[2] ?? null);

                $portal = new SupplierPortal();
                $portal->setCompany($company);
                $portal->setPortalUrl($row[7] ?? null);
                $portal->setNotes($row[8] ?? null);

                $this->entityManager->persist($company);
                $this->entityManager->persist($portal);
                $count++;

                if ($count % 20 == 0) {
                    $this->entityManager->flush();
                    $output->writeln("Seeded $count records...");
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();
            $output->writeln("<info>Successfully seeded $count supplier portal records</info>");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
