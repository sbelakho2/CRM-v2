<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add confidence scoring and manual override fields to bom_line table
 */
final class Version20260125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confidence scoring and manual override fields to bom_line for Quote CoPilot improvements';
    }

    public function up(Schema $schema): void
    {
        // Add confidence scoring fields
        $this->ifColumnMissing('bom_lines', 'original_mpn', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD original_mpn VARCHAR(255) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'matched_mpn', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD matched_mpn VARCHAR(255) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'bom_description', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD bom_description TEXT DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'confidence_score', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD confidence_score SMALLINT DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'confidence_level', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD confidence_level VARCHAR(20) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'confidence_reasons', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD confidence_reasons JSON DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'confidence_warnings', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD confidence_warnings JSON DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'requires_review', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD requires_review TINYINT(1) DEFAULT 0');
});

        
        // Add manual override fields
        $this->ifColumnMissing('bom_lines', 'manually_verified', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD manually_verified TINYINT(1) DEFAULT 0');
});

        $this->ifColumnMissing('bom_lines', 'manual_unit_price', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD manual_unit_price DECIMAL(10, 4) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'manual_notes', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD manual_notes TEXT DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'verified_by', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD verified_by VARCHAR(100) DEFAULT NULL');
});

        $this->ifColumnMissing('bom_lines', 'verified_at', function (): void {
    $this->addSql('ALTER TABLE bom_lines ADD verified_at DATETIME DEFAULT NULL');
});

        
        // Add index for review status queries
        $this->ifIndexMissing('bom_lines', 'idx_bom_review', function (): void {
    $this->addSql('CREATE INDEX idx_bom_review ON bom_lines (requires_review)');
});

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bom_review ON bom_line');
        $this->addSql('ALTER TABLE bom_line DROP original_mpn');
        $this->addSql('ALTER TABLE bom_line DROP matched_mpn');
        $this->addSql('ALTER TABLE bom_line DROP bom_description');
        $this->addSql('ALTER TABLE bom_line DROP confidence_score');
        $this->addSql('ALTER TABLE bom_line DROP confidence_level');
        $this->addSql('ALTER TABLE bom_line DROP confidence_reasons');
        $this->addSql('ALTER TABLE bom_line DROP confidence_warnings');
        $this->addSql('ALTER TABLE bom_line DROP requires_review');
        $this->addSql('ALTER TABLE bom_line DROP manually_verified');
        $this->addSql('ALTER TABLE bom_line DROP manual_unit_price');
        $this->addSql('ALTER TABLE bom_line DROP manual_notes');
        $this->addSql('ALTER TABLE bom_line DROP verified_by');
        $this->addSql('ALTER TABLE bom_line DROP verified_at');
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
