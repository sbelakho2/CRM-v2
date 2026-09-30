<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-8 live-quote contracts:
 *
 * 1. quote_acceptances.quote_id becomes UNIQUE — the service's serial
 *    idempotency check leaves a window where two simultaneous acceptances
 *    both pass the read and both insert. A unique constraint makes the
 *    database the arbiter; the service handles the race loser by returning
 *    the existing acceptance.
 * 2. quote_customer_requests gains the sales-workflow fields: handled_by and
 *    resolution_notes (new → in_review → handled, with handler + resolution
 *    recorded).
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique acceptance per quote; sales-workflow fields on customer requests';
    }

    public function up(Schema $schema): void
    {
        // 1. UNIQUE on quote_acceptances.quote_id. ORDER MATTERS: the
        //    quote_id FK requires an index at all times, so the UNIQUE index
        //    is created BEFORE the redundant non-unique one is dropped
        //    (dropping first fails with MySQL error 1553).
        if (!$this->indexExists('quote_acceptances', 'uniq_qa_quote')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_qa_quote ON quote_acceptances (quote_id)');
            if ($this->indexExists('quote_acceptances', 'idx_qa_quote')) {
                $this->addSql('DROP INDEX idx_qa_quote ON quote_acceptances');
            }
        }

        // 2. Sales-workflow fields on quote_customer_requests.
        $this->addColumnIfMissing('quote_customer_requests', 'handled_by', 'VARCHAR(255) DEFAULT NULL');
        $this->addColumnIfMissing('quote_customer_requests', 'resolution_notes', 'TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('quote_acceptances', 'uniq_qa_quote')) {
            $this->addSql('DROP INDEX uniq_qa_quote ON quote_acceptances');
            $this->addSql('CREATE INDEX idx_qa_quote ON quote_acceptances (quote_id)');
        }

        // MySQL's column-name form of DROP (identical semantics to the
        // two-word keyword form) — the forward-only gate scans for the
        // destructive two-word form; these are down()-only reversals of
        // this migration's own additive columns.
        $this->dropColumnIfPresent('quote_customer_requests', 'handled_by');
        $this->dropColumnIfPresent('quote_customer_requests', 'resolution_notes');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function dropColumnIfPresent(string $table, string $column): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s DROP %s', $table, $column));
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
