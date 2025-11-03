<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251029151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email Campaign enhancements and missing entities';
    }

    public function up(Schema $schema): void
    {
        // Create email_template table
        $this->addSql('CREATE TABLE IF NOT EXISTS email_template (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            subject_line VARCHAR(255) NOT NULL, 
            preview_text VARCHAR(255) DEFAULT NULL, 
            body_html CLOB NOT NULL, 
            body_text CLOB DEFAULT NULL, 
            description CLOB DEFAULT NULL, 
            category VARCHAR(50) DEFAULT NULL, 
            is_active BOOLEAN NOT NULL, 
            personalization_tokens CLOB DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME DEFAULT NULL, 
            created_by VARCHAR(100) DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_template_name ON email_template (name)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_template_active ON email_template (is_active)');

        // Create email_segment table
        $this->addSql('CREATE TABLE IF NOT EXISTS email_segment (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            name VARCHAR(255) NOT NULL, 
            description CLOB DEFAULT NULL, 
            filter_rules_json CLOB NOT NULL, 
            contact_count INTEGER NOT NULL, 
            is_active BOOLEAN NOT NULL, 
            last_calculated_at DATETIME DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME DEFAULT NULL, 
            created_by VARCHAR(100) DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_segment_name ON email_segment (name)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_segment_active ON email_segment (is_active)');

        // Create email_unsubscribe table
        $this->addSql('CREATE TABLE IF NOT EXISTS email_unsubscribe (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            contact_id INTEGER DEFAULT NULL, 
            email VARCHAR(255) NOT NULL, 
            reason VARCHAR(100) DEFAULT NULL, 
            feedback_text CLOB DEFAULT NULL, 
            unsubscribed_at DATETIME NOT NULL, 
            ip_address VARCHAR(45) DEFAULT NULL, 
            user_agent VARCHAR(255) DEFAULT NULL, 
            CONSTRAINT FK_email_unsubscribe_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_unsubscribe_contact ON email_unsubscribe (contact_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_unsubscribe_email ON email_unsubscribe (email)');

        // Add new columns to email_campaigns
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN send_time_optimization BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE email_campaigns ADD COLUMN ab_test_variants CLOB DEFAULT NULL');

        // Create missing entities tables
        $this->addSql('CREATE TABLE IF NOT EXISTS bom_line (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            quote_id INTEGER NOT NULL, 
            line_number INTEGER NOT NULL, 
            mpn VARCHAR(255) DEFAULT NULL, 
            manufacturer VARCHAR(255) DEFAULT NULL, 
            description CLOB DEFAULT NULL, 
            quantity INTEGER NOT NULL, 
            unit_price NUMERIC(10, 4) DEFAULT NULL, 
            extended_price NUMERIC(12, 2) DEFAULT NULL, 
            procurement_source VARCHAR(50) DEFAULT NULL, 
            has_exception BOOLEAN DEFAULT NULL, 
            exception_reason VARCHAR(100) DEFAULT NULL, 
            lead_time_days INTEGER DEFAULT NULL, 
            availability VARCHAR(20) DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME DEFAULT NULL, 
            CONSTRAINT FK_bom_line_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_bom_quote ON bom_line (quote_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_bom_mpn ON bom_line (mpn)');

        $this->addSql('CREATE TABLE IF NOT EXISTS procurement_exception (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            bom_line_id INTEGER NOT NULL, 
            exception_type VARCHAR(50) NOT NULL, 
            severity VARCHAR(20) NOT NULL, 
            message CLOB DEFAULT NULL, 
            recommendation CLOB DEFAULT NULL, 
            metadata CLOB DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            CONSTRAINT FK_proc_exc_bomline FOREIGN KEY (bom_line_id) REFERENCES bom_line (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_proc_exc_bomline ON procurement_exception (bom_line_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_proc_exc_severity ON procurement_exception (severity)');

        $this->addSql('CREATE TABLE IF NOT EXISTS dfm_finding (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            quote_id INTEGER NOT NULL, 
            dfm_rule_id INTEGER DEFAULT NULL, 
            finding_type VARCHAR(100) NOT NULL, 
            severity VARCHAR(20) NOT NULL, 
            description CLOB NOT NULL, 
            remediation CLOB DEFAULT NULL, 
            cost_impact NUMERIC(10, 2) DEFAULT NULL, 
            lead_time_impact INTEGER DEFAULT NULL, 
            metadata CLOB DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            CONSTRAINT FK_dfm_finding_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) NOT DEFERRABLE INITIALLY IMMEDIATE, 
            CONSTRAINT FK_dfm_finding_rule FOREIGN KEY (dfm_rule_id) REFERENCES dfm_rules (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dfm_quote ON dfm_finding (quote_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dfm_severity ON dfm_finding (severity)');

        $this->addSql('CREATE TABLE IF NOT EXISTS abm_account (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            account_name VARCHAR(255) NOT NULL, 
            domain VARCHAR(255) NOT NULL, 
            icp_tier VARCHAR(20) DEFAULT NULL, 
            engagement_score INTEGER DEFAULT NULL, 
            total_visits INTEGER DEFAULT NULL, 
            total_page_views INTEGER DEFAULT NULL, 
            first_seen_at DATETIME DEFAULT NULL, 
            last_activity_at DATETIME DEFAULT NULL, 
            metadata CLOB DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_abm_domain ON abm_account (domain)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_abm_domain ON abm_account (domain)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_abm_icp_tier ON abm_account (icp_tier)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_abm_engagement ON abm_account (engagement_score)');

        $this->addSql('CREATE TABLE IF NOT EXISTS dataset_version (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, 
            dataset_type VARCHAR(100) NOT NULL, 
            version_uuid VARCHAR(36) NOT NULL, 
            sha256_hash VARCHAR(64) NOT NULL, 
            record_count INTEGER DEFAULT NULL, 
            is_active BOOLEAN DEFAULT NULL, 
            metadata CLOB DEFAULT NULL, 
            imported_at DATETIME NOT NULL, 
            imported_by VARCHAR(100) DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_dataset_uuid ON dataset_version (version_uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dataset_type ON dataset_version (dataset_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_dataset_active ON dataset_version (is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS email_template');
        $this->addSql('DROP TABLE IF EXISTS email_segment');
        $this->addSql('DROP TABLE IF EXISTS email_unsubscribe');
        $this->addSql('DROP TABLE IF EXISTS procurement_exception');
        $this->addSql('DROP TABLE IF EXISTS bom_line');
        $this->addSql('DROP TABLE IF EXISTS dfm_finding');
        $this->addSql('DROP TABLE IF EXISTS abm_account');
        $this->addSql('DROP TABLE IF EXISTS dataset_version');
    }
}
