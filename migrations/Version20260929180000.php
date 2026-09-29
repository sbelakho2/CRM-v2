<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-5 residual completion (forward-only, additive):
 *
 *  1. email_sends.provider_message_id — transport-assigned message
 *     identifier for delivery/bounce reconciliation.
 *  2. worker_heartbeats — background-worker liveness markers read by the
 *     system-health probe.
 */
final class Version20260929180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round 5: provider message IDs and worker heartbeats';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('email_sends', 'provider_message_id')) {
            $this->addSql('ALTER TABLE email_sends ADD COLUMN provider_message_id VARCHAR(255) DEFAULT NULL');
        }

        if (!$this->tableExists('worker_heartbeats')) {
            $this->addSql('CREATE TABLE worker_heartbeats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                last_run_at DATETIME NOT NULL,
                run_count INT NOT NULL DEFAULT 0,
                last_result VARCHAR(255) DEFAULT NULL,
                UNIQUE KEY uniq_worker_name (name)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Additive completion columns are kept.');
    }

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
}
