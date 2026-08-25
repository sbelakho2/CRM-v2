<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email verification: add is_verified column to the users table.
 * Existing accounts are backfilled as verified so that no pre-existing
 * user is locked out; only accounts created via self-registration
 * (which explicitly set is_verified = 0) require verification.
 */
final class Version20260807010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_verified column to users table for email verification';
    }

    public function up(Schema $schema): void
    {
        // The entity maps the table as `users`, but legacy migration-built
        // schemas may still use the singular `user` (corrective migration
        // Version20260807030000 renames it afterwards). Support both so this
        // migration applies cleanly on either schema shape.
        $existing = $this->connection->executeQuery(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ('users', 'user')"
        )->fetchFirstColumn();

        $tableName = null;
        if (in_array('users', $existing, true)) {
            $tableName = 'users';
        } elseif (in_array('user', $existing, true)) {
            $tableName = 'user';
        }

        if ($tableName === null) {
            return;
        }

        // Baseline and migrated schemas already carry is_verified; only the
        // legacy pre-migration shape needs the ADD (the UPDATE is idempotent).
        if (!$this->columnExists($tableName, 'is_verified')) {
            $this->addSql(sprintf('ALTER TABLE `%s` ADD is_verified TINYINT(1) DEFAULT 0 NOT NULL', $tableName));
            $this->addSql(sprintf('UPDATE `%s` SET is_verified = 1', $tableName));
        }
    }

    public function down(Schema $schema): void
    {
        $existing = $this->connection->executeQuery(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN ('users', 'user')
               AND is_verified IS NOT NULL"
        )->fetchFirstColumn();

        $tableName = in_array('users', $existing, true) ? 'users' : (in_array('user', $existing, true) ? 'user' : null);
        if ($tableName !== null) {
            $this->addSql(sprintf('ALTER TABLE `%s` DROP is_verified', $tableName));
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->fetchOne();
    }
}
