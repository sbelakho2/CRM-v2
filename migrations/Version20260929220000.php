<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-6 completion: closes the last entity/service divergences
 * (forward-only, additive). The 55 legacy undefined-method call sites are
 * now backed by real columns:
 *
 *  - capacity_calendars.bookings — structured booking ledger (quoteId,
 *    quantity, bookedAt) replacing the phantom bookingsJson
 *  - dfm_finding.resolution / resolution_notes / resolved_at — the
 *    finding-resolution lifecycle DfmLintService manages
 *  - compliance_documents document-management columns (entity_id,
 *    entity_type, sha256_hash, version_number, generated_at, generated_by,
 *    version_id, metadata_json, deleted_at, deleted_by) — generated-artifact
 *    tracking + soft delete for DocumentManagerService/PDF flows
 */
final class Version20260929220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round-6 completion: capacity bookings ledger, DFM resolution lifecycle, compliance document-management columns';
    }

    public function up(Schema $schema): void
    {
        $this->addColumnIfMissing('capacity_calendars', 'bookings', 'JSON DEFAULT NULL');
        $this->addColumnIfMissing('dfm_finding', 'resolution', 'VARCHAR(20) DEFAULT NULL');
        $this->addColumnIfMissing('dfm_finding', 'resolution_notes', 'LONGTEXT DEFAULT NULL');
        $this->addColumnIfMissing('dfm_finding', 'resolved_at', 'DATETIME DEFAULT NULL');

        $this->addColumnIfMissing('compliance_documents', 'entity_id', 'INT DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'entity_type', 'VARCHAR(100) DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'sha256_hash', 'VARCHAR(64) DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'version_number', 'INT DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'generated_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'generated_by', 'VARCHAR(100) DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'version_id', 'VARCHAR(100) DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'metadata_json', 'JSON DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'deleted_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('compliance_documents', 'deleted_by', 'VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Additive completion columns are kept.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
