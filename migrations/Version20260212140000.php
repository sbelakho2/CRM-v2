<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize legacy hex accent colors to named values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE users SET accent_color = \'orange\' WHERE LOWER(accent_color) IN (\'#ffbe00\',\'ffbe00\')');

        $this->addSql('UPDATE users SET accent_color = \'blue\' WHERE LOWER(accent_color) IN (\'#3b82f6\',\'3b82f6\')');

        $this->addSql('UPDATE users SET accent_color = \'green\' WHERE LOWER(accent_color) IN (\'#22c55e\',\'22c55e\')');

        $this->addSql('UPDATE users SET accent_color = \'purple\' WHERE LOWER(accent_color) IN (\'#8b5cf6\',\'8b5cf6\')');

        $this->addSql('UPDATE users SET accent_color = \'red\' WHERE LOWER(accent_color) IN (\'#ef4444\',\'ef4444\')');

    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE users SET accent_color = '#FFBE00' WHERE accent_color = 'orange'");
        $this->addSql("UPDATE users SET accent_color = '#3B82F6' WHERE accent_color = 'blue'");
        $this->addSql("UPDATE users SET accent_color = '#22C55E' WHERE accent_color = 'green'");
        $this->addSql("UPDATE users SET accent_color = '#8B5CF6' WHERE accent_color = 'purple'");
        $this->addSql("UPDATE users SET accent_color = '#EF4444' WHERE accent_color = 'red'");
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
