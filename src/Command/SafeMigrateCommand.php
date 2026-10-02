<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * THE production database-deployment entry point.
 *
 * An operator following the deployment docs runs ONLY this command — never
 * `doctrine:migrations:migrate` directly. Direct migration entry points can
 * enter the historical chain past Version20260824120000, which DROPS legacy
 * compliance_documents columns; without preservation the data is gone.
 *
 * This wrapper makes the safe order mandatory and automatic:
 *   1. detect pending migrations (nothing pending → no-op success)
 *   2. detect pre-drop legacy compliance columns/data → archive them into
 *      compliance_legacy_preserved, clear the live columns (refreshing
 *      values, never stale), verify coverage
 *   3. preflight checks (abort on any failure)
 *   4. migrate to latest
 *   5. verify the restored values byte-for-byte against the archive
 *   6. verify the chain is at latest and entity mappings are valid
 * It aborts (non-zero exit) on ANY mismatch before touching more state.
 */
#[AsCommand(
    name: 'app:migrations:safe-migrate',
    description: 'Production-safe database migration: preflight → preserve legacy compliance data → migrate → verify restoration → validate',
)]
class SafeMigrateCommand extends Command
{
    public function __construct(
        private Connection $connection,
        private DependencyFactory $dependencyFactory,
        private ManagerRegistry $managerRegistry,
        private PreserveComplianceLegacyCommand $preserveCommand,
        private MigrationsPreflightCommand $preflightCommand,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Safe production migration');

        $database = (string) $this->connection->getDatabase();
        $io->writeln(sprintf('Target database: <fg=yellow;options=bold>%s</>', $database));

        // ── 1. Pending migrations ─────────────────────
        $io->section('Step 1/6 · Pending migrations');
        $statusCalculator = $this->dependencyFactory->getMigrationStatusCalculator();
        $pending = $statusCalculator->getNewMigrations();
        if (count($pending) === 0) {
            $io->success('Database is already at the latest migration — nothing to do.');

            return Command::SUCCESS;
        }
        foreach ($pending->getItems() as $migration) {
            $io->writeln(sprintf('  · %s', (string) $migration->getVersion()));
        }

        // ── 2. Preserve pre-drop legacy compliance data ──
        // Era detection: document_type exists ONLY before the drop
        // migration — an already-latest database (sha256_hash/version_id
        // re-added, values restored) can never re-trigger preservation.
        $preservationDone = false;
        $io->section('Step 2/6 · Legacy compliance preservation');
        if (!$this->columnExists('compliance_documents', 'document_type')) {
            $io->writeln('  Pre-drop era not detected — preservation not applicable.');
        } else {
            if ($this->runNested($this->preserveCommand, $input, $output) !== Command::SUCCESS) {
                $io->error('Preservation failed — migration aborted, NOTHING was changed.');

                return Command::FAILURE;
            }
            $preservationDone = true;
        }

        // ── 3. Preflight ──────────────────────────────
        $io->section('Step 3/6 · Preflight');
        if ($this->runNested($this->preflightCommand, $input, $output) !== Command::SUCCESS) {
            $io->error('Preflight failed — migration aborted. Follow the reported blockers and re-run.');

            return Command::FAILURE;
        }

        // ── 4. Migrate ────────────────────────────────
        $io->section('Step 4/6 · Migrating to latest');
        $this->dependencyFactory->getMetadataStorage()->ensureInitialized();

        $latest = $this->dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $this->dependencyFactory->getMigrationPlanCalculator()->getPlanUntilVersion($latest);

        if (count($plan) === 0) {
            $io->success('No migrations to execute.');

            return Command::SUCCESS;
        }

        try {
            $sql = $this->dependencyFactory->getMigrator()->migrate($plan, new MigratorConfiguration());
        } catch (\Throwable $e) {
            $io->error('Migration FAILED: ' . $e->getMessage());
            $io->warning('The database may be in a partially-migrated state. Run the preflight and inspect before ANY retry.');

            return Command::FAILURE;
        }
        foreach ($sql as $version => $statements) {
            $io->writeln(sprintf('  ✓ %s (%d statement(s))', $version, count($statements)));
        }

        // ── 5. Verify restoration byte-for-byte ───────
        if ($preservationDone && $this->columnExists('compliance_documents', 'sha256_hash')) {
            $io->section('Step 5/6 · Verifying restored legacy compliance values');
            $rawMismatches = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM compliance_legacy_preserved p
                 JOIN compliance_documents d ON d.id = p.document_id
                 WHERE (p.sha256_hash IS NOT NULL AND p.sha256_hash <> '' AND (d.sha256_hash IS NULL OR d.sha256_hash <> p.sha256_hash))
                    OR (p.version_id IS NOT NULL AND p.version_id <> '' AND (d.version_id IS NULL OR d.version_id <> p.version_id))"
            );
            $mismatches = is_numeric($rawMismatches) ? (int) $rawMismatches : 0;

            if ($mismatches > 0) {
                $io->error(sprintf(
                    '%d restored row(s) do NOT match the preserved archive — the migration chain lost data. Investigate before proceeding.',
                    $mismatches
                ));

                return Command::FAILURE;
            }
            $io->writeln('  ✓ every preserved value restored byte-for-byte');
        } else {
            $io->section('Step 5/6 · No preservation to verify');
        }

        // ── 6. Chain at latest + mapping valid ────────
        $io->section('Step 6/6 · Final validation');
        $stillPending = count($this->dependencyFactory->getMigrationStatusCalculator()->getNewMigrations());
        if ($stillPending > 0) {
            $io->error(sprintf('%d migration(s) still pending after migrate — unexpected state.', $stillPending));

            return Command::FAILURE;
        }

        $em = $this->managerRegistry->getManager();
        if (!$em instanceof EntityManagerInterface) {
            throw new \RuntimeException('Default entity manager is not an ORM entity manager.');
        }
        $validator = new SchemaValidator($em);
        $mappingErrors = $validator->validateMapping();
        if ($mappingErrors !== []) {
            $io->error('Entity mapping validation failed after migration:');
            foreach ($mappingErrors as $class => $messages) {
                foreach ($messages as $message) {
                    $io->writeln(sprintf('  · %s: %s', $class, $message));
                }
            }

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Safe migration complete on %s: %d migration(s) applied%s. Database verified at latest, mappings valid.',
            $database,
            count($plan),
            $preservationDone ? ', legacy compliance data preserved and restored' : ''
        ));

        return Command::SUCCESS;
    }

    private function runNested(Command $command, InputInterface $input, OutputInterface $output): int
    {
        return $command->run(new ArrayInput([]), $output);
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }
}
