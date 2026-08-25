<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the estimates table — QuoteEstimator module removed (Option A rationalization).
 * 
 * The Estimate entity and its controller/templates/services have been fully removed.
 * All quoting functionality is consolidated in the QuoteCoPilot → QuoteReview → LiveQuote pipeline.
 */
final class Version20260211180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop estimates table — QuoteEstimator module removed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS estimates');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE estimates (
            id INT AUTO_INCREMENT NOT NULL,
            company_id INT NOT NULL,
            rfq_id INT DEFAULT NULL,
            estimate_number VARCHAR(50) NOT NULL,
            origin_country VARCHAR(100) NOT NULL,
            destination_country VARCHAR(100) NOT NULL,
            origin_port VARCHAR(100) DEFAULT NULL,
            destination_port VARCHAR(100) DEFAULT NULL,
            material_cost NUMERIC(15, 2) NOT NULL,
            labor_cost NUMERIC(15, 2) NOT NULL,
            freight_cost NUMERIC(15, 2) NOT NULL,
            duty_cost NUMERIC(15, 2) NOT NULL,
            other_costs NUMERIC(15, 2) DEFAULT NULL,
            total_landed_cost NUMERIC(15, 2) NOT NULL,
            currency VARCHAR(10) NOT NULL,
            duty_rate NUMERIC(5, 2) DEFAULT NULL,
            fta_agreement VARCHAR(100) DEFAULT NULL,
            fta_qualified TINYINT(1) NOT NULL DEFAULT 0,
            bom_data LONGTEXT DEFAULT NULL,
            sha256_hash VARCHAR(64) DEFAULT NULL,
            version_id VARCHAR(100) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_A0E35E301E4A0F44 (estimate_number),
            INDEX idx_company (company_id),
            INDEX idx_estimate_number (estimate_number),
            PRIMARY KEY(id),
            CONSTRAINT FK_estimates_company FOREIGN KEY (company_id) REFERENCES companies (id),
            CONSTRAINT FK_estimates_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
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
