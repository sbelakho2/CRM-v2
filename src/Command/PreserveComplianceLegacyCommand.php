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
 * Run this BEFORE `doctrine:migrations:migrate` on any populated database
 * that still has the legacy columns. Idempotent: rows already archived are
 * skipped, never duplicated.
 *
 * This is the deterministic preservation path the migration-preflight
 * command points operators at.
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

        // 1. The legacy columns must still exist.
        if (!$this->columnExists('compliance_documents', 'sha256_hash')) {
            $io->success('Legacy compliance columns already dropped — nothing to preserve.');

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

        // 3. Copy every row carrying legacy values (idempotent: skipped if
        //    already archived).
        $preserved = $this->connection->executeStatement(
            'INSERT INTO compliance_legacy_preserved (document_id, document_type, sha256_hash, version_id, preserved_at)
             SELECT id, document_type, sha256_hash, version_id, NOW()
             FROM compliance_documents
             WHERE (document_type IS NOT NULL AND document_type <> \'\')
                OR (sha256_hash IS NOT NULL AND sha256_hash <> \'\')
                OR (version_id IS NOT NULL AND version_id <> \'\')
             ON DUPLICATE KEY UPDATE preserved_at = VALUES(preserved_at)'
        );

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM compliance_legacy_preserved'
        );

        $io->success(sprintf(
            'Preserved %d row(s) into compliance_legacy_preserved (%d total archived). ' .
            'Run doctrine:migrations:migrate — the chain will drop the legacy columns and ' .
            'Version20260929230000 restores the values into the recreated columns.',
            $preserved,
            $count
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
