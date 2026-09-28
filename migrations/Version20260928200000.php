<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-2 data-preservation hardening (forward-only, additive, never
 * destructive — same model as Version20260928120000):
 *
 *  1. Archive columns for email_campaigns, contacts, rfqs and webinars —
 *     their UI "delete" actions archive; history stays queryable.
 *  2. email_campaigns.scheduled_dispatched_at for exactly-once claiming of
 *     due scheduled campaigns.
 *  3. Retention-safe FK conversions (CASCADE -> RESTRICT) so campaign/
 *     contact rows with engagement history can never be silently cascaded
 *     away: email_sends.campaign, email_sends.contact,
 *     outbound_messages.contact. Constraint names are discovered from
 *     information_schema, never assumed.
 *  4. UNIQUE(company_id, document_key) on compliance_documents — created
 *     ONLY when no duplicate (company, document_key) pairs exist; MySQL
 *     allows multiple NULLs so legacy/custom rows are unaffected.
 */
final class Version20260928200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data-preservation round 2: archive campaigns/contacts/RFQs/webinars, scheduler claim column, retention FKs, compliance key uniqueness';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Archive columns ─────────────────────────────────────────────

        $archiveTables = [
            'email_campaigns' => false, // no archive_reason column
            'contacts' => true,
            'rfqs' => true,
            'webinars' => true,
        ];

        // Doctrine's conventional FK constraint names for these
        // (table, archived_by_id) pairs (IDX_<tablehash><columnhash>).
        $archiveFkNames = [
            'email_campaigns' => 'IDX_EC78EB5B77BE2925',
            'contacts' => 'IDX_3340157377BE2925',
            'rfqs' => 'IDX_530068A877BE2925',
            'webinars' => 'IDX_8DCE851A77BE2925',
        ];

        foreach (array_keys($archiveTables) as $table) {
            $this->addColumnIfMissing($table, 'archived_at', 'DATETIME DEFAULT NULL');
            $this->addColumnIfMissing($table, 'archived_by_id', 'INT DEFAULT NULL');
            $this->addForeignKeyIfMissing($table, ['archived_by_id'], 'users', $archiveFkNames[$table], 'SET NULL');
        }
        foreach (array_keys(array_filter($archiveTables)) as $table) {
            $this->addColumnIfMissing($table, 'archive_reason', 'VARCHAR(500) DEFAULT NULL');
        }

        // ── 2. Scheduler exactly-once claim column ─────────────────────────

        $this->addColumnIfMissing('email_campaigns', 'scheduled_dispatched_at', 'DATETIME DEFAULT NULL');

        // ── 3. Retention FKs: CASCADE -> RESTRICT ──────────────────────────

        $this->convertForeignKeyToRestrict('email_sends', ['campaign_id'], 'email_campaigns');
        $this->convertForeignKeyToRestrict('email_sends', ['contact_id'], 'contacts');
        $this->convertForeignKeyToRestrict('outbound_messages', ['contact_id'], 'contacts');

        // ── 4. Compliance (company, document_key) uniqueness ────────────────

        if ($this->tableExists('compliance_documents') && !$this->indexExists('compliance_documents', 'uniq_compliance_company_key')) {
            $duplicates = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT company_id, document_key
                    FROM compliance_documents
                    WHERE document_key IS NOT NULL
                    GROUP BY company_id, document_key
                    HAVING COUNT(*) > 1
                    LIMIT 1
                ) dupes'
            );

            if ($duplicates === 0) {
                $this->addSql(
                    'CREATE UNIQUE INDEX uniq_compliance_company_key'
                    .' ON compliance_documents (company_id, document_key)'
                );
            } else {
                $this->warnIf(true, 'compliance_documents contains duplicate (company, document_key) rows; uniqueness index skipped to avoid destroying data. Reconcile the pack (generate-pack), then re-create the index manually.');
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Data-preservation constraints are kept; reversing would reintroduce destructive cascades.');
    }

    // ── helpers (same fail-safe pattern as Version20260928120000) ─────────

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

    private function addColumnIfMissing(string $table, string $column, ?string $definition): void
    {
        if ($definition === null || !$this->tableExists($table) || $this->columnExists($table, $column)) {
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
