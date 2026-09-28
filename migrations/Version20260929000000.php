<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-3 data-preservation hardening (forward-only, additive, never
 * destructive — same model as the two previous rounds):
 *
 *  1. email_campaigns.archive_reason — the entity API expected it but no
 *     column existed (round-2 omission).
 *  2. email_sends.send_lease_expires_at — crash-recoverable delivery
 *     ownership: a stale lease makes stuck QUEUED/SENDING rows claimable
 *     again instead of permanently "in progress".
 *  3. webinar_attendees.company_name + confirmation_sent_at — free-text
 *     company for external registrants (never fuzzy-matched into Company
 *     entities) and resilient confirmation-email tracking.
 *  4. Archive columns for activities, playbooks and abm_account — their
 *     "delete" actions archive now, preserving run/hit history.
 */
final class Version20260929000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data-preservation round 3: campaign archive_reason, send leases, webinar attendee fields, activity/playbook/ABM archives';
    }

    public function up(Schema $schema): void
    {
        $this->addColumnIfMissing('email_campaigns', 'archive_reason', 'VARCHAR(500) DEFAULT NULL');
        $this->addColumnIfMissing('email_sends', 'send_lease_expires_at', 'DATETIME DEFAULT NULL');
        $this->addColumnIfMissing('webinar_attendees', 'company_name', 'VARCHAR(255) DEFAULT NULL');
        $this->addColumnIfMissing('webinar_attendees', 'confirmation_sent_at', 'DATETIME DEFAULT NULL');

        // Doctrine's conventional FK names for (table, archived_by_id).
        $archiveFkNames = [
            'activities' => 'IDX_B5F1AFE577BE2925',
            'playbooks' => 'IDX_1C3BA45877BE2925',
            'abm_account' => 'IDX_EEBF221A77BE2925',
        ];

        foreach (array_keys($archiveFkNames) as $table) {
            $this->addColumnIfMissing($table, 'archived_at', 'DATETIME DEFAULT NULL');
            $this->addColumnIfMissing($table, 'archived_by_id', 'INT DEFAULT NULL');
            $this->addColumnIfMissing($table, 'archive_reason', 'VARCHAR(500) DEFAULT NULL');
            $this->addForeignKeyIfMissing($table, ['archived_by_id'], 'users', $archiveFkNames[$table], 'SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Data-preservation constraints are kept; reversing would reintroduce destructive cascades.');
    }

    // ── helpers (same fail-safe pattern as previous rounds) ───────────────

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
}
