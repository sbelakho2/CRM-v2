<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add snooze functionality to compliance_documents table
 * 
 * This migration adds three columns to support temporary alert suppression:
 * - snoozed_until: DATE - alerts are suppressed until this date
 * - snooze_reason: VARCHAR(255) - optional explanation for snoozing
 * - snoozed_by: VARCHAR(100) - user who initiated the snooze
 */
final class Version20260126190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snooze functionality to compliance_documents (snoozed_until, snooze_reason, snoozed_by)';
    }

    public function up(Schema $schema): void
    {
        // Add snooze columns to compliance_documents
        $this->ifColumnMissing('compliance_documents', 'snoozed_until', function (): void {
    $this->addSql('ALTER TABLE compliance_documents ADD snoozed_until DATE DEFAULT NULL');
});

        $this->ifColumnMissing('compliance_documents', 'snooze_reason', function (): void {
    $this->addSql('ALTER TABLE compliance_documents ADD snooze_reason VARCHAR(255) DEFAULT NULL');
});

        $this->ifColumnMissing('compliance_documents', 'snoozed_by', function (): void {
    $this->addSql('ALTER TABLE compliance_documents ADD snoozed_by VARCHAR(100) DEFAULT NULL');
});

        
        // Add index for efficient queries on snoozed documents
        $this->ifIndexMissing('compliance_documents', 'IDX_compliance_docs_snoozed', function (): void {
    $this->addSql('CREATE INDEX IDX_compliance_docs_snoozed ON compliance_documents (snoozed_until)');
});

    }

    public function down(Schema $schema): void
    {
        // Remove index
        $this->addSql('DROP INDEX IDX_compliance_docs_snoozed ON compliance_documents');
        
        // Remove columns
        $this->addSql('ALTER TABLE compliance_documents DROP snoozed_until');
        $this->addSql('ALTER TABLE compliance_documents DROP snooze_reason');
        $this->addSql('ALTER TABLE compliance_documents DROP snoozed_by');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetchOne();
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->fetchOne();
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->fetchOne();
    }

    private function hasSchema(): bool
    {
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'doctrine_migration_versions'"
        );
        return $count > 0;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $constraint]
        )->fetchOne();
    }

    private function ifConstraintMissing(string $table, string $constraint, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->constraintExists($table, $constraint)) {
            return;
        }
        $fn();
    }

    private function ifColumnMissing(string $table, string $column, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }
        $fn();
    }

    private function ifIndexMissing(string $table, string $index, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->indexExists($table, $index)) {
            return;
        }
        $fn();
    }

    private function ifIndexExists(string $table, string $index, callable $fn): void
    {
        if ($this->tableExists($table) && $this->indexExists($table, $index)) {
            $fn();
        }
    }

    private function ifTableEmpty(string $table, callable $fn): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");
        if ($count === 0) {
            $fn();
        }
    }
}
