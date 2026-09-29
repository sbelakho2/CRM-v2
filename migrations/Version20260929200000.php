<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-6 P0-2 reconciliation (forward-only, additive): SupplierPortal gains
 * the submission-configuration columns the submission path reads — portal
 * vendor classification, login/submit endpoints, form-field mapping and the
 * file-upload requirement. Previously those existed only in the service's
 * imagination (undefined-method crashes at runtime).
 */
final class Version20260929200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round 6: SupplierPortal submission-config columns (vendor, login/submit URLs, form fields, upload requirement)';
    }

    public function up(Schema $schema): void
    {
        $this->addColumnIfMissing('supplier_portals', 'portal_vendor', 'VARCHAR(50) DEFAULT NULL');
        $this->addColumnIfMissing('supplier_portals', 'login_url', 'VARCHAR(2048) DEFAULT NULL');
        $this->addColumnIfMissing('supplier_portals', 'submit_url', 'VARCHAR(2048) DEFAULT NULL');
        $this->addColumnIfMissing('supplier_portals', 'form_fields_json', 'LONGTEXT DEFAULT NULL');
        $this->addColumnIfMissing('supplier_portals', 'requires_file_upload', 'TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Additive reconciliation columns are kept.');
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
