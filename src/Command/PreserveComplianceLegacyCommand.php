<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Preserves legacy compliance_documents columns (document_type, sha256_hash,
 * version_id) into a durable archive table BEFORE the historical
 * Version20260824120000 drop runs, so the post-recreation restore migration
 * (Version20260929230000) can copy them back into the re-added columns.
 *
 * After every carrying row is verified archived, the live legacy columns are
 * CLEARED (the values live on in the archive) so the migration preflight no
 * longer blocks — this is the manual step operators previously had to
 * remember, now part of the command itself. The chain then drops the empty
 * legacy columns and Version20260929230000 restores the archived values.
 *
 * Era-safe: document_type exists ONLY in the pre-drop era, so running this
 * on an already-migrated database (where sha256_hash/version_id were
 * re-added but document_type was not) is a no-op — it can never re-archive
 * and clear restored data.
 *
 * Invoked automatically by app:migrations:safe-migrate; also usable directly.
 * Idempotent and refreshing: re-runs UPDATE the archive to the final
 * pre-migration state.
 */
#[AsCommand(
    name: 'app:migrations:preserve-compliance-legacy',
    description: 'Archive legacy compliance_documents columns before the schema-drop migration runs',
)]
class PreserveComplianceLegacyCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 1. The LEGACY era is identified by document_type (never re-added
        //    by the chain). Without it, sha256_hash/version_id are the
        //    POST-RESTORE columns — archived data already lives there.
        if (!$this->columnExists('compliance_documents', 'document_type')
            || !$this->columnExists('compliance_documents', 'sha256_hash')) {
            $io->success('No pre-drop legacy compliance columns present — nothing to preserve.');

            return Command::SUCCESS;
        }

        // 2. Durable archive table (kept after restore for audit).
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS compliance_legacy_preserved (
                document_id INT NOT NULL PRIMARY KEY,
                document_type VARCHAR(255) DEFAULT NULL,
                sha256_hash VARCHAR(64) DEFAULT NULL,
                version_id VARCHAR(100) DEFAULT NULL,
                preserved_at DATETIME NOT NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );

        $legacyRowCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM compliance_documents
             WHERE (document_type IS NOT NULL AND document_type <> '')
                OR (sha256_hash IS NOT NULL AND sha256_hash <> '')
                OR (version_id IS NOT NULL AND version_id <> '')"
        );

        if ($legacyRowCount === 0) {
            $io->success('Legacy compliance columns present but no values remain — nothing to preserve.');

            return Command::SUCCESS;
        }

        // 3. Copy every row carrying legacy values. Idempotent AND
        //    refreshing: if this ran once, the legacy fields changed, and it
        //    runs again, the archived values are UPDATED to the final
        //    pre-migration state (never stale first-run values).
        $this->connection->executeStatement(
            'INSERT INTO compliance_legacy_preserved (document_id, document_type, sha256_hash, version_id, preserved_at)
             SELECT id, document_type, sha256_hash, version_id, NOW()
             FROM compliance_documents
             WHERE (document_type IS NOT NULL AND document_type <> \'\')
                OR (sha256_hash IS NOT NULL AND sha256_hash <> \'\')
                OR (version_id IS NOT NULL AND version_id <> \'\')
             ON DUPLICATE KEY UPDATE
                document_type = VALUES(document_type),
                sha256_hash = VALUES(sha256_hash),
                version_id = VALUES(version_id),
                preserved_at = VALUES(preserved_at)'
        );

        // 4. Verify the archive covers every carrying row BEFORE touching
        //    the live columns.
        $archivedCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM compliance_legacy_preserved');
        if ($archivedCount < $legacyRowCount) {
            $io->error(sprintf(
                'Preservation incomplete: %d legacy row(s) present but only %d archived — live columns left untouched.',
                $legacyRowCount,
                $archivedCount
            ));

            return Command::FAILURE;
        }

        // 5. Clear the live legacy columns — the values are durable in the
        //    archive; the drop migration is now safe and the preflight no
        //    longer blocks. Version20260929230000 restores from the archive.
        $cleared = $this->connection->executeStatement(
            "UPDATE compliance_documents
             SET document_type = NULL, sha256_hash = NULL, version_id = NULL
             WHERE (document_type IS NOT NULL AND document_type <> '')
                OR (sha256_hash IS NOT NULL AND sha256_hash <> '')
                OR (version_id IS NOT NULL AND version_id <> '')"
        );

        $io->success(sprintf(
            'Preserved and verified %d row(s) (%d total archived) into compliance_legacy_preserved and cleared the ' .
            'live legacy columns. The migration chain is now safe: Version20260824120000 drops empty legacy columns ' .
            'and Version20260929230000 restores the archived values.',
            $cleared,
            $archivedCount
        ));

        return Command::SUCCESS;
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }
}
