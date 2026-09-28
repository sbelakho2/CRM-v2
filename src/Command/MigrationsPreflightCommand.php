<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Production migration preflight: run BEFORE doctrine:migrations:migrate on
 * an existing (data-rich) database.
 *
 * Historical migrations are immutable and some legitimately contain
 * destructive SQL guarded only by assumptions that held when they were
 * written. The CI validates fresh-database -> latest; this command validates
 * POPULATED database -> latest for the known destructive cases, so a
 * deployment aborts instead of destroying rows.
 *
 * Currently guarded migrations:
 *   - Version20260211180000 drops `estimates`: abort if the table exists,
 *     still contains rows, and the migration has not yet been executed.
 */
#[AsCommand(
    name: 'app:migrations:preflight',
    description: 'Abort deployment when pending migrations would destroy existing data',
)]
class MigrationsPreflightCommand extends Command
{
    public function __construct(
        private Connection $connection,
        private DependencyFactory $dependencyFactory,
    ) {
        parent::__construct();
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $executed = array_map(
            static fn (ExecutedMigration $m) => $m->getVersion(),
            $this->dependencyFactory->getMigrationRepository()->getExecutedMigrations()->getItems()
        );

        $blockers = [];

        // ── Version20260211180000: DROP TABLE IF EXISTS estimates ──────────
        $version = 'DoctrineMigrations\\Version20260211180000';
        if (!in_array($version, $executed, true) && $this->tableExists('estimates')) {
            $rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM estimates');
            if ($rows > 0) {
                $blockers[] = sprintf(
                    'Migration %s would DROP the `estimates` table which still contains %d row(s). '.
                    'Preserve or deliberately transform that data first (e.g. archive to legacy_estimates), '.
                    'record the migration as handled, and re-run this preflight.',
                    $version,
                    $rows
                );
            }
        }

        if ($blockers !== []) {
            $io->error('Migration preflight FAILED — deployment must not proceed:');
            foreach ($blockers as $blocker) {
                $io->writeln('  ✖ '.$blocker);
            }

            return Command::FAILURE;
        }

        $io->success('Migration preflight passed: no pending migration destroys existing data.');

        return Command::SUCCESS;
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
