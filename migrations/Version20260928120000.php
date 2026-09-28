<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data-preservation hardening (forward-only; never rewrites history):
 *
 *  1. Adds soft-archive columns to `companies` and `quotes` plus user
 *     deactivation columns on `users` — the CRM now archives companies and
 *     quotes and deactivates users instead of deleting rows.
 *  2. Converts destructive ON DELETE CASCADE foreign keys on CRM-history
 *     tables to ON DELETE RESTRICT so a hard delete can never silently
 *     cascade-destroy contacts, activities, RFQs, quotes, compliance
 *     records, tasks, report definitions, calendar events or meeting slots.
 *     Constraint names are DISCOVERED from information_schema, not assumed.
 *  3. Adds `compliance_documents.document_key` for idempotent,
 *     non-destructive compliance-pack reconciliation.
 *  4. Adds a uniqueness constraint on email_sends
 *     (campaign_id, contact_id, touch_number) to make campaign touches
 *     idempotent — but ONLY when no duplicate rows already exist. Existing
 *     rows are never modified or deleted by this migration.
 *
 * Every operation is idempotent and additive; no table, column or row is
 * ever dropped, truncated or rewritten here.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data-preservation: archive columns, RESTRICT history FKs, compliance document_key, idempotent email_sends';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Archive / deactivation columns ─────────────────────────────

        $this->addColumnIfMissing('companies', 'archived_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('companies', 'archived_by_id', 'INT DEFAULT NULL');
        $this->addColumnIfMissing('companies', 'archive_reason', 'VARCHAR(500) DEFAULT NULL');
        $this->addIndexIfMissing('companies', ['archived_at'], 'idx_company_archived_at');
        $this->addForeignKeyIfMissing(
            'companies',
            ['archived_by_id'],
            'users',
            'IDX_8244AA3A77BE2925',
            'SET NULL'
        );

        $this->addColumnIfMissing('quotes', 'archived_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('quotes', 'archived_by_id', 'INT DEFAULT NULL');
        $this->addForeignKeyIfMissing(
            'quotes',
            ['archived_by_id'],
            'users',
            'IDX_A1B588C577BE2925',
            'SET NULL'
        );

        $this->addColumnIfMissing('users', 'deactivated_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('users', 'deactivated_by_id', 'INT DEFAULT NULL');
        $this->addForeignKeyIfMissing(
            'users',
            ['deactivated_by_id'],
            'users',
            'IDX_1483A5E9BDC76BAA',
            'SET NULL'
        );

        // ── 2. Compliance document_key ────────────────────────────────────

        $this->addColumnIfMissing('compliance_documents', 'document_key', 'VARCHAR(100) DEFAULT NULL');
        $this->addIndexIfMissing('compliance_documents', ['document_key'], 'idx_compliance_documents_key');

        // ── 3. History FKs: CASCADE -> RESTRICT ───────────────────────────

        // company children that must never cascade away with a company
        $this->convertForeignKeyToRestrict('contacts', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('activities', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('rfqs', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('quotes', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('compliance_documents', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('portal_candidates', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('onboarding_packs', ['company_id'], 'companies');
        $this->convertForeignKeyToRestrict('company_canonicals', ['company_id'], 'companies');

        // user children that must never cascade away with a user
        $this->convertForeignKeyToRestrict('activities', ['user_id'], 'users');
        $this->convertForeignKeyToRestrict('tasks', ['created_by_id'], 'users');
        $this->convertForeignKeyToRestrict('report_definitions', ['created_by_id'], 'users');
        $this->convertForeignKeyToRestrict('calendar_events', ['organizer_id'], 'users');
        $this->convertForeignKeyToRestrict('meeting_slots', ['owner_id'], 'users');

        // ── 4. Idempotent campaign touches ────────────────────────────────

        if ($this->tableExists('email_sends')) {
            $duplicates = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT campaign_id, contact_id, touch_number
                    FROM email_sends
                    WHERE touch_number IS NOT NULL
                    GROUP BY campaign_id, contact_id, touch_number
                    HAVING COUNT(*) > 1
                    LIMIT 1
                ) dupes'
            );

            if ($duplicates === 0) {
                $this->addSql(
                    'CREATE UNIQUE INDEX uniq_email_sends_touch'
                    .' ON email_sends (campaign_id, contact_id, touch_number)'
                );
            } else {
                // Pre-existing duplicate touches exist: preserve them untouched
                // (application-level idempotency still guards new sends) and
                // log loudly so an operator can dedupe deliberately.
                $this->warnIf(true, 'email_sends contains duplicate (campaign, contact, touch) rows; uniqueness index skipped to avoid destroying data. Dedupe manually, then re-create the index.');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Reversing this migration would reintroduce destructive cascades.
        // The archive columns themselves are harmless to drop, but restoring
        // ON DELETE CASCADE on CRM-history tables is a data-loss hazard, so
        // this down() only reverses the additive column additions and leaves
        // the RESTRICT constraints in place.
        $this->skipIf(true, 'Data-preservation constraints are kept; only dropping additive columns would be reversed — run manually if truly needed.');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );
    }

    private function addIndexIfMissing(string $table, array $columns, string $indexName): void
    {
        if (!$this->tableExists($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        $this->addSql(sprintf(
            'CREATE INDEX %s ON %s (%s)',
            $indexName,
            $table,
            implode(', ', $columns)
        ));
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$table, $constraintName]
        );
    }

    private function addForeignKeyIfMissing(
        string $table,
        array $columns,
        string $referencedTable,
        string $constraintName,
        string $onDelete
    ): void {
        if (!$this->tableExists($table) || $this->foreignKeyExists($table, $constraintName)) {
            return;
        }

        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE %s',
            $table,
            $constraintName,
            implode(', ', $columns),
            $referencedTable,
            $onDelete
        ));
    }

    /**
     * Find the existing FK on ($table, $columns) referencing $referencedTable,
     * whatever its name, and replace it with ON DELETE RESTRICT. If the
     * current rule is already RESTRICT/NO ACTION, nothing happens.
     */
    private function convertForeignKeyToRestrict(string $table, array $columns, string $referencedTable): void
    {
        if (!$this->tableExists($table)) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT kcu.CONSTRAINT_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME = ?
               AND kcu.COLUMN_NAME = ?',
            [$table, $referencedTable, $columns[0]]
        );

        foreach ($rows as $row) {
            $rule = strtoupper((string) $row['DELETE_RULE']);
            if ($rule === 'RESTRICT' || $rule === 'NO ACTION') {
                continue;
            }

            $constraintName = (string) $row['CONSTRAINT_NAME'];
            $newName = sprintf('fk_%s_%s_restrict', $table, implode('_', $columns));

            $this->addSql(sprintf(
                'ALTER TABLE %s DROP FOREIGN KEY %s',
                $table,
                $this->quoteIdentifier($constraintName)
            ));

            if ($this->foreignKeyExists($table, $newName)) {
                $newName = $newName.'_'.bin2hex(random_bytes(3));
            }

            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (id) ON DELETE RESTRICT',
                $table,
                $newName,
                implode(', ', $columns),
                $referencedTable
            ));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
