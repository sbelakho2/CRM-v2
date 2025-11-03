<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Email Campaign Service enhancements
 * Adds new fields to email_campaigns, email_sends, and contacts tables
 */
final class Version20251029154000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fields for Email Campaign services (status, timestamps, template, segment)';
    }

    public function up(Schema $schema): void
    {
        // Add new columns to email_campaigns
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'draft\'');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN scheduled_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN sent_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN template_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN segment_id INTEGER DEFAULT NULL');

        // Add new columns to email_sends
        $this->addSql('ALTER TABLE email_sends ADD COLUMN email_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT \'queued\'');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN scheduled_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN opened_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN clicked_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE email_sends ADD COLUMN failure_reason TEXT DEFAULT NULL');

        // Make sent_at nullable in email_sends (it was NOT NULL before)
        $this->addSql('CREATE TEMPORARY TABLE __temp__email_sends AS SELECT * FROM email_sends');
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
        $this->addSql('INSERT INTO email_sends SELECT * FROM __temp__email_sends');
        $this->addSql('DROP TABLE __temp__email_sends');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_633143B3F639F774 ON email_sends (campaign_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_633143B3E7A1254A ON email_sends (contact_id)');

        // Add new columns to contacts
        $this->addSql('ALTER TABLE contacts ADD COLUMN subscribed BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE contacts ADD COLUMN lead_score INTEGER NOT NULL DEFAULT 0');

        // Create indexes for foreign keys
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EC78EB5B5DA0FB8 ON email_campaigns (template_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EC78EB5BDB296AAD ON email_campaigns (segment_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove new columns from email_campaigns
        $this->addSql('DROP INDEX IF EXISTS IDX_EC78EB5B5DA0FB8');
        $this->addSql('DROP INDEX IF EXISTS IDX_EC78EB5BDB296AAD');
        
        $this->addSql('CREATE TEMPORARY TABLE __temp__email_campaigns AS SELECT id, name, language, description, touch_count, touch_templates, active, send_time_optimization, ab_test_variants FROM email_campaigns');
        $this->addSql('DROP TABLE email_campaigns');
        $this->addSql('CREATE TABLE email_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            language VARCHAR(10) NOT NULL,
            description TEXT DEFAULT NULL,
            touch_count INTEGER NOT NULL,
            touch_templates TEXT NOT NULL,
            active BOOLEAN NOT NULL,
            send_time_optimization BOOLEAN DEFAULT 0 NOT NULL,
            ab_test_variants TEXT DEFAULT NULL
        )');
        $this->addSql('INSERT INTO email_campaigns SELECT id, name, language, description, touch_count, touch_templates, active, send_time_optimization, ab_test_variants FROM __temp__email_campaigns');
        $this->addSql('DROP TABLE __temp__email_campaigns');

        // Remove new columns from email_sends
        $this->addSql('CREATE TEMPORARY TABLE __temp__email_sends AS SELECT id, campaign_id, contact_id, touch_number, sent_at, opened, clicked, replied, bounced FROM email_sends');
        $this->addSql('DROP TABLE email_sends');
        $this->addSql('CREATE TABLE email_sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            campaign_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            touch_number INTEGER NOT NULL,
            sent_at DATETIME NOT NULL,
            opened BOOLEAN DEFAULT 0 NOT NULL,
            clicked BOOLEAN DEFAULT 0 NOT NULL,
            replied BOOLEAN DEFAULT 0 NOT NULL,
            bounced BOOLEAN DEFAULT 0 NOT NULL,
            CONSTRAINT FK_633143B3F639F774 FOREIGN KEY (campaign_id) REFERENCES email_campaigns (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_633143B3E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO email_sends SELECT id, campaign_id, contact_id, touch_number, sent_at, opened, clicked, replied, bounced FROM __temp__email_sends');
        $this->addSql('DROP TABLE __temp__email_sends');
        $this->addSql('CREATE INDEX IDX_633143B3F639F774 ON email_sends (campaign_id)');
        $this->addSql('CREATE INDEX IDX_633143B3E7A1254A ON email_sends (contact_id)');

        // Remove new columns from contacts
        $this->addSql('CREATE TEMPORARY TABLE __temp__contacts AS SELECT id, company_id, first_name, last_name, job_title, email, phone, linked_in_url, source, primary_contact, notes, created_at FROM contacts');
        $this->addSql('DROP TABLE contacts');
        $this->addSql('CREATE TABLE contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            company_id INTEGER NOT NULL,
            first_name VARCHAR(255) NOT NULL,
            last_name VARCHAR(255) NOT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            linked_in_url VARCHAR(255) DEFAULT NULL,
            source VARCHAR(50) DEFAULT NULL,
            primary_contact BOOLEAN DEFAULT 0 NOT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT FK_33401573979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO contacts SELECT id, company_id, first_name, last_name, job_title, email, phone, linked_in_url, source, primary_contact, notes, created_at FROM __temp__contacts');
        $this->addSql('DROP TABLE __temp__contacts');
        $this->addSql('CREATE INDEX IDX_33401573979B1AD6 ON contacts (company_id)');
    }
}
