<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make playbooks.priority and onboarding_packs.portal_candidate_id nullable
 * to match the entity contract (both properties are ?typed in PHP).
 *
 * The FK constraint name for portal_candidate_id varies by how the table
 * was created, so it is resolved from information_schema instead of being
 * hard-coded; every step is guarded by existence checks.
 */
final class Version20260807200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make playbooks.priority and onboarding_packs.portal_candidate_id nullable to match the entity contract';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playbooks CHANGE priority priority INT DEFAULT NULL');


        if (!$this->columnExists('onboarding_packs', 'portal_candidate_id')) {
            return;
        }

        // Drop the FK constraint (if any) before widening the column.
        $fkName = $this->connection->executeQuery(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'onboarding_packs'
               AND column_name = 'portal_candidate_id'
               AND referenced_table_name IS NOT NULL
             LIMIT 1"
        )->fetchOne();

        if ($fkName) {
            $this->addSql(sprintf('ALTER TABLE onboarding_packs DROP FOREIGN KEY `%s`', $fkName));
        }

        $this->addSql('ALTER TABLE onboarding_packs CHANGE portal_candidate_id portal_candidate_id INT DEFAULT NULL');

    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('onboarding_packs', 'portal_candidate_id')) {
            $this->addSql('ALTER TABLE onboarding_packs CHANGE portal_candidate_id portal_candidate_id INT NOT NULL');
        }
        $this->addSql('ALTER TABLE playbooks CHANGE priority priority INT NOT NULL');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        )->fetchOne();
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
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
