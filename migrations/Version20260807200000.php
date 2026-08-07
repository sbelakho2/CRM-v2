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
}
