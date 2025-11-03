<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Smart Notification System
 * Creates notification table for system-wide notification management
 */
final class Version20251030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification table for Smart Notification Center';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            user_id INTEGER NOT NULL,
            type VARCHAR(50) NOT NULL,
            entity_type VARCHAR(100) DEFAULT NULL,
            entity_id INTEGER DEFAULT NULL,
            message TEXT NOT NULL,
            data JSON DEFAULT NULL,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE INDEX idx_notification_user_read ON notification(user_id, read_at)');
        $this->addSql('CREATE INDEX idx_notification_created ON notification(created_at)');
        $this->addSql('CREATE INDEX idx_notification_entity ON notification(entity_type, entity_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
    }
}
