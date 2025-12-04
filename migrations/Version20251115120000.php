<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add variant column to email_sends table for A/B testing support
 */
final class Version20251115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add variant column to email_sends table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_sends ADD COLUMN variant VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__email_sends AS SELECT id, campaign_id, contact_id, touch_number, email_address, status, scheduled_at, sent_at, opened_at, clicked_at, opened, clicked, replied, bounced, retry_count, failure_reason FROM email_sends');
        $this->addSql('DROP TABLE email_sends');
        $this->addSql('CREATE TABLE email_sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            campaign_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            touch_number INTEGER NOT NULL,
            email_address VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'queued\',
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            opened BOOLEAN DEFAULT 0 NOT NULL,
            clicked BOOLEAN DEFAULT 0 NOT NULL,
            replied BOOLEAN DEFAULT 0 NOT NULL,
            bounced BOOLEAN DEFAULT 0 NOT NULL,
            retry_count INTEGER DEFAULT 0 NOT NULL,
            failure_reason TEXT DEFAULT NULL,
            CONSTRAINT FK_633143B3F639F774 FOREIGN KEY (campaign_id) REFERENCES email_campaigns (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_633143B3E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO email_sends SELECT id, campaign_id, contact_id, touch_number, email_address, status, scheduled_at, sent_at, opened_at, clicked_at, opened, clicked, replied, bounced, retry_count, failure_reason FROM __temp__email_sends');
        $this->addSql('DROP TABLE __temp__email_sends');
        $this->addSql('CREATE INDEX IDX_633143B3F639F774 ON email_sends (campaign_id)');
        $this->addSql('CREATE INDEX IDX_633143B3E7A1254A ON email_sends (contact_id)');
    }
}
