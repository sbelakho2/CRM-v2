<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260126155252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Activity status/subject/duration, Playbook cooldown hours, and RFQ win/loss competitive intelligence fields';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->ifColumnMissing('activities', 'subject', function (): void {
    $this->addSql('ALTER TABLE activities ADD subject VARCHAR(255) DEFAULT NULL, ADD outcome_detail VARCHAR(50) DEFAULT NULL, ADD status VARCHAR(50) DEFAULT NULL, ADD duration_minutes INT DEFAULT NULL');
});

        $this->ifColumnMissing('playbooks', 'cooldown_hours', function (): void {
    $this->addSql('ALTER TABLE playbooks ADD cooldown_hours INT DEFAULT 24');
});

        $this->ifColumnMissing('rfqs', 'loss_reason', function (): void {
    $this->addSql('ALTER TABLE rfqs ADD loss_reason VARCHAR(50) DEFAULT NULL, ADD loss_reason_detail LONGTEXT DEFAULT NULL, ADD competitor_won VARCHAR(255) DEFAULT NULL, ADD winning_bid_amount NUMERIC(15, 2) DEFAULT NULL, ADD lessons_learned LONGTEXT DEFAULT NULL, ADD win_factors LONGTEXT DEFAULT NULL, ADD decision_date DATE DEFAULT NULL, ADD award_date DATE DEFAULT NULL');
});

    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activities DROP subject, DROP outcome_detail, DROP status, DROP duration_minutes');
        $this->addSql('ALTER TABLE playbooks DROP cooldown_hours');
        $this->addSql('ALTER TABLE rfqs DROP loss_reason, DROP loss_reason_detail, DROP competitor_won, DROP winning_bid_amount, DROP lessons_learned, DROP win_factors, DROP decision_date, DROP award_date');
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
