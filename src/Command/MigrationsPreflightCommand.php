<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\ExecutedMigration;
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
            static fn (ExecutedMigration $m): string => (string) $m->getVersion(),
            $this->dependencyFactory->getMetadataStorage()->getExecutedMigrations()->getItems()
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

        // ── Version20260824120000: DROP COLUMN on populated columns ────────
        // The migration assumes the dropped columns are unused. Verify the
        // assumption against live data: non-default values are history.
        $dropColumnChecks = [
            'DoctrineMigrations\\Version20260824120000' => [
                ['contacts', 'subscribed'],
                ['contacts', 'lead_score'],
                ['compliance_documents', 'document_type'],
                ['compliance_documents', 'sha256_hash'],
                ['compliance_documents', 'version_id'],
            ],
        ];
        foreach ($dropColumnChecks as $version => $columns) {
            if (in_array($version, $executed, true)) {
                continue;
            }
            foreach ($columns as [$table, $column]) {
                if (!$this->columnExists($table, $column)) {
                    continue;
                }
                // Type-aware populated check: comparing a string column to
                // numeric 0 invokes MySQL's numeric coercion (non-numeric
                // strings coerce to 0) and can classify real history as
                // empty. Text columns test TRIM(col) <> ''; numeric
                // columns test col <> 0 (plus NOT NULL).
                $dataType = (string) $this->connection->fetchOne(
                    'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );
                $isText = in_array($dataType, ['char', 'varchar', 'text', 'longtext', 'mediumtext', 'tinytext'], true);
                // 0/false is the empty/default state for numeric and
                // boolean flags; NULL is empty for nullable columns.
                $predicate = $isText
                    ? "{$column} IS NOT NULL AND TRIM({$column}) <> ''"
                    : "{$column} IS NOT NULL AND {$column} <> 0";
                $nonNull = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM {$table} WHERE {$predicate}"
                );
                if ($nonNull > 0) {
                    $blockers[] = sprintf(
                        'Migration %s would DROP %s.%s which still holds %d populated value(s). '.
                        'Migrate or deliberately archive that data first.',
                        $version,
                        $table,
                        $column,
                        $nonNull
                    );
                }
            }
        }

        // ── Version20260824110000: DELETE FROM webinar_attendees ───────────
        // This migration merges duplicate attendees before deleting the
        // losers (preservation-first), so it is a warning rather than a
        // blocker — but it deserves upgrade-path attention.
        $version = 'DoctrineMigrations\\Version20260824110000';
        if (!in_array($version, $executed, true) && $this->tableExists('webinar_attendees')) {
            $dupes = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (SELECT webinar_id, email FROM webinar_attendees GROUP BY webinar_id, email HAVING COUNT(*) > 1 LIMIT 1) d'
            );
            if ($dupes > 0) {
                $io->warning('Migration DoctrineMigrations\Version20260824110000 merges duplicate webinar attendees (preservation-first). Review its merge logic against your data before upgrading.');
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

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
