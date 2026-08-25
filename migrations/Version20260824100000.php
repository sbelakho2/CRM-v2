<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen user-entered URL columns from VARCHAR(255) to VARCHAR(2048).
 *
 * Long URLs (e.g. company websites, LinkedIn profile links) previously
 * exceeded the 255-char column limit and caused a DataTooLongException
 * (HTTP 500) on save. None of these columns are indexed, so widening is
 * safe; the change is purely additive and preserves all existing data.
 * The MODIFY statements are idempotent.
 */
final class Version20260824100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen URL columns (companies.website, companies.linked_in_url, contacts.linked_in_url) to VARCHAR(2048)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies MODIFY website VARCHAR(2048) DEFAULT NULL');

        $this->addSql('ALTER TABLE companies MODIFY linked_in_url VARCHAR(2048) DEFAULT NULL');

        $this->addSql('ALTER TABLE contacts MODIFY linked_in_url VARCHAR(2048) DEFAULT NULL');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies MODIFY website VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE companies MODIFY linked_in_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE contacts MODIFY linked_in_url VARCHAR(255) DEFAULT NULL');
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
