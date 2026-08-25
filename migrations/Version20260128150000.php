<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add lifecycle tracking, source URL tracking, and alternative parts fields to BomLine.
 * Part of Quote CoPilot improvements for multi-distributor waterfall and transparency.
 */
final class Version20260128150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lifecycle_status, lifecycle_warning, price_source_url, distributor_search_url, and alternative_parts fields to bom_line table';
    }

    public function up(Schema $schema): void
    {
        // Add lifecycle tracking columns
        $this->ifColumnMissing('bom_lines', 'lifecycle_status', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD lifecycle_status VARCHAR(50) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'lifecycle_warning', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD lifecycle_warning VARCHAR(20) DEFAULT NULL');
});

        
        // Add source URL tracking for transparency
        $this->ifColumnMissing('bom_lines', 'price_source_url', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD price_source_url VARCHAR(500) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'distributor_search_url', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD distributor_search_url VARCHAR(500) DEFAULT NULL');
});

        
        // Add alternative parts JSON storage
        $this->ifColumnMissing('bom_lines', 'alternative_parts', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD alternative_parts JSON DEFAULT NULL');
});

        
        // Add index for lifecycle filtering
        $this->ifIndexMissing('bom_lines', 'IDX_bom_line_lifecycle', function (): void {
    $this->addSql('CREATE INDEX IDX_bom_line_lifecycle ON bom_lines (lifecycle_warning)');
});

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_bom_line_lifecycle ON bom_line');
        $this->addSql('ALTER TABLE bom_line DROP lifecycle_status');
        $this->addSql('ALTER TABLE bom_line DROP lifecycle_warning');
        $this->addSql('ALTER TABLE bom_line DROP price_source_url');
        $this->addSql('ALTER TABLE bom_line DROP distributor_search_url');
        $this->addSql('ALTER TABLE bom_line DROP alternative_parts');
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
