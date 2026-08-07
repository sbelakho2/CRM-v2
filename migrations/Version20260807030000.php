<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrective schema-drift migration (idempotent, MySQL):
 *
 * (a) RENAME legacy singular table names to the plural names mapped by the
 *     current entities — task -> tasks, user -> users, bom_line -> bom_lines —
 *     but only when the source exists and the target does not.
 * (b) DROP orphaned tables that no longer have an entity mapping:
 *     estimates (removed in an earlier migration cycle) and legacy
 *     competitor_* tables (the mapped table is competitor_detection,
 *     which is protected from this sweep).
 * (c) Create missing FK indexes required by the current entity mappings:
 *     activities(user_id, activity_date), activities(company_id),
 *     email_sends(campaign_id), email_sends(contact_id),
 *     quotes(contact_id), quotes(rfq_id).
 *
 * Every step checks existence first, so the migration can be re-run safely.
 */
final class Version20260807030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corrective migration: rename legacy singular tables, drop orphaned tables, add missing FK indexes';
    }

    public function up(Schema $schema): void
    {
        // ── (a) Rename legacy singular tables to entity-mapped plural names ──
        $this->renameTableIfExists('task', 'tasks');
        $this->renameTableIfExists('user', 'users');
        $this->renameTableIfExists('bom_line', 'bom_lines');

        // ── (b) Drop orphaned tables (no entity mapping) ──
        $this->dropTableIfExists('estimates');

        $conn = $this->connection;
        $orphanRows = $conn->executeQuery(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE 'competitor\_%'"
        )->fetchFirstColumn();

        foreach ($orphanRows as $tableName) {
            // 'competitor_detection' (singular) IS mapped by CompetitorDetection entity — never drop it.
            if ($tableName !== 'competitor_detection') {
                $this->dropTableIfExists((string) $tableName);
            }
        }

        // ── (c) Create missing FK indexes (check column exists first) ──
        $this->createCompositeIndexIfColumnExists(
            'activities', 'idx_activities_user_date', ['user_id', 'activity_date']
        );
        $this->createIndexIfColumnExists('activities', 'idx_activities_company', 'company_id');
        $this->createIndexIfColumnExists('email_sends', 'idx_email_sends_campaign', 'campaign_id');
        $this->createIndexIfColumnExists('email_sends', 'idx_email_sends_contact', 'contact_id');
        $this->createIndexIfColumnExists('quotes', 'idx_quotes_contact', 'contact_id');
        $this->createIndexIfColumnExists('quotes', 'idx_quotes_rfq', 'rfq_id');
    }

    public function down(Schema $schema): void
    {
        $this->dropIndexIfExists('quotes', 'idx_quotes_rfq');
        $this->dropIndexIfExists('quotes', 'idx_quotes_contact');
        $this->dropIndexIfExists('email_sends', 'idx_email_sends_contact');
        $this->dropIndexIfExists('email_sends', 'idx_email_sends_campaign');
        $this->dropIndexIfExists('activities', 'idx_activities_company');
        $this->dropIndexIfExists('activities', 'idx_activities_user_date');

        // Reverse renames only when the plural table is still the source of truth
        // (i.e. when the target has been renamed back already).
        $this->renameTableIfExists('tasks', 'task');
        $this->renameTableIfExists('users', 'user');
        $this->renameTableIfExists('bom_lines', 'bom_line');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        )->fetchOne();
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        )->fetchOne();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        )->fetchOne();
    }

    private function renameTableIfExists(string $from, string $to): void
    {
        if ($this->tableExists($from) && !$this->tableExists($to)) {
            $this->addSql("RENAME TABLE `{$from}` TO `{$to}`");
        }
    }

    private function dropTableIfExists(string $table): void
    {
        if ($this->tableExists($table)) {
            $this->addSql("DROP TABLE `{$table}`");
        }
    }

    private function createIndexIfColumnExists(string $table, string $indexName, string $column): void
    {
        if (!$this->columnExists($table, $column) || $this->indexExists($table, $indexName)) {
            return;
        }
        $this->addSql("CREATE INDEX `{$indexName}` ON `{$table}` (`{$column}`)");
    }

    private function createCompositeIndexIfColumnExists(string $table, string $indexName, array $columns): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        foreach ($columns as $column) {
            if (!$this->columnExists($table, $column)) {
                return;
            }
        }
        $quoted = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $this->addSql("CREATE INDEX `{$indexName}` ON `{$table}` ({$quoted})");
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->addSql("DROP INDEX `{$indexName}` ON `{$table}`");
        }
    }
}
