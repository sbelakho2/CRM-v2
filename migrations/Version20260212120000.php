<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales Pipeline Rationalization — add missing FKs and nurturing stage column.
 *
 * Changes:
 *   rfqs          + contact_id  FK → contacts(id) ON DELETE SET NULL
 *   rfqs          + lead_id     FK → leads(id) ON DELETE SET NULL
 *   outbound_messages + rfq_id  FK → rfqs(id) ON DELETE SET NULL
 *   leads         + nurturing_stage VARCHAR(30) NULLABLE + INDEX
 *
 * NOTE: this migration is a duplicate of Version20260211120000. On a fresh
 * database both are executed, so every statement here is guarded by
 * existence checks: if Version20260211120000 already applied the schema,
 * this migration becomes a no-op instead of failing with
 * "Duplicate column/constraint/index" errors.
 */
final class Version20260212120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales Pipeline Rationalization: add RFQ.contact_id, RFQ.lead_id, OutboundMessage.rfq_id FKs, Lead.nurturing_stage column';
    }

    public function up(Schema $schema): void
    {
        // NOTE: existence checks evaluate the DB state at plan time, so each
        // statement is guarded independently. The ALTER ADD column is planned
        // first, which guarantees the column for the FK/index statements that
        // follow in the same run.

        // -- rfqs: add contact_id FK -----------------------------------------
        $this->addColumnIfMissing('rfqs', 'contact_id', 'INT DEFAULT NULL');
        if (!$this->foreignKeyOnColumnExists('rfqs', 'contact_id')) {
            $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F1E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        }
        $this->addIndexIfMissing('rfqs', 'IDX_RFQS_CONTACT', 'contact_id');

        // -- rfqs: add lead_id FK --------------------------------------------
        $this->addColumnIfMissing('rfqs', 'lead_id', 'INT DEFAULT NULL');
        if (!$this->foreignKeyOnColumnExists('rfqs', 'lead_id')) {
            $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F155458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL');
        }
        $this->addIndexIfMissing('rfqs', 'IDX_RFQS_LEAD', 'lead_id');

        // -- outbound_messages: add rfq_id FK --------------------------------
        $this->addColumnIfMissing('outbound_messages', 'rfq_id', 'INT DEFAULT NULL');
        if (!$this->foreignKeyOnColumnExists('outbound_messages', 'rfq_id')) {
            $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_OBM_RFQ FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL');
        }
        $this->addIndexIfMissing('outbound_messages', 'IDX_OBM_RFQ', 'rfq_id');

        // -- leads: add nurturing_stage column + index -----------------------
        $this->addColumnIfMissing('leads', 'nurturing_stage', 'VARCHAR(30) DEFAULT NULL');
        $this->addIndexIfMissing('leads', 'idx_leads_nurturing', 'nurturing_stage');
    }

    public function down(Schema $schema): void
    {
        // -- leads: drop nurturing_stage
        $this->dropIndexIfExists('leads', 'idx_leads_nurturing');
        $this->dropColumnIfExists('leads', 'nurturing_stage');

        // -- outbound_messages: drop rfq_id FK
        $this->dropForeignKeyOnColumn('outbound_messages', 'rfq_id');
        $this->dropIndexIfExists('outbound_messages', 'IDX_OBM_RFQ');
        $this->dropColumnIfExists('outbound_messages', 'rfq_id');

        // -- rfqs: drop lead_id FK
        $this->dropForeignKeyOnColumn('rfqs', 'lead_id');
        $this->dropIndexIfExists('rfqs', 'IDX_RFQS_LEAD');
        $this->dropColumnIfExists('rfqs', 'lead_id');

        // -- rfqs: drop contact_id FK
        $this->dropForeignKeyOnColumn('rfqs', 'contact_id');
        $this->dropIndexIfExists('rfqs', 'IDX_RFQS_CONTACT');
        $this->dropColumnIfExists('rfqs', 'contact_id');
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
        if (!$this->tableExists($table)) {
            return false;
        }
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        )->fetchOne();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        )->fetchOne();
    }

    private function foreignKeyOnColumnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        return (bool) $this->connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
               AND referenced_table_name IS NOT NULL",
            [$table, $column]
        )->fetchOne();
    }

    private function dropForeignKeyOnColumn(string $table, string $column): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $constraintName = $this->connection->executeQuery(
            "SELECT constraint_name FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
               AND referenced_table_name IS NOT NULL
             LIMIT 1",
            [$table, $column]
        )->fetchOne();

        if ($constraintName) {
            $this->addSql("ALTER TABLE {$table} DROP FOREIGN KEY `{$constraintName}`");
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        $this->addSql("ALTER TABLE {$table} ADD {$column} {$definition}");
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (!$this->columnExists($table, $column)) {
            return;
        }
        $this->addSql("ALTER TABLE {$table} DROP {$column}");
    }

    private function addIndexIfMissing(string $table, string $indexName, string $column): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }
        $this->addSql("CREATE INDEX {$indexName} ON {$table} ({$column})");
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->indexExists($table, $indexName)) {
            return;
        }
        $this->addSql("DROP INDEX {$indexName} ON {$table}");
    }
}
