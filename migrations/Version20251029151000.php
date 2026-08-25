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
        $this->addSql('CREATE TABLE IF NOT EXISTS email_template ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, name VARCHAR(255) NOT NULL, subject_line VARCHAR(255) NOT NULL, preview_text VARCHAR(255) DEFAULT NULL, body_html LONGTEXT NOT NULL, body_text LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(50) DEFAULT NULL, is_active TINYINT(1) NOT NULL, personalization_tokens LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL )');

        $this->ifIndexMissing('email_template', 'idx_template_name', function (): void {
    $this->addSql('CREATE INDEX idx_template_name ON email_template (name)');
});

        $this->ifIndexMissing('email_template', 'idx_template_active', function (): void {
    $this->addSql('CREATE INDEX idx_template_active ON email_template (is_active)');
});


        // Create email_segment table
        $this->addSql('CREATE TABLE IF NOT EXISTS email_segment ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, filter_rules_json LONGTEXT NOT NULL, contact_count INTEGER NOT NULL, is_active TINYINT(1) NOT NULL, last_calculated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL )');

        $this->ifIndexMissing('email_segment', 'idx_segment_name', function (): void {
    $this->addSql('CREATE INDEX idx_segment_name ON email_segment (name)');
});

        $this->ifIndexMissing('email_segment', 'idx_segment_active', function (): void {
    $this->addSql('CREATE INDEX idx_segment_active ON email_segment (is_active)');
});


        // Create email_unsubscribe table
        $this->addSql('CREATE TABLE IF NOT EXISTS email_unsubscribe ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, contact_id INTEGER DEFAULT NULL, email VARCHAR(255) NOT NULL, reason VARCHAR(100) DEFAULT NULL, feedback_text LONGTEXT DEFAULT NULL, unsubscribed_at DATETIME NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, CONSTRAINT FK_email_unsubscribe_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) )');

        $this->ifIndexMissing('email_unsubscribe', 'idx_unsubscribe_contact', function (): void {
    $this->addSql('CREATE INDEX idx_unsubscribe_contact ON email_unsubscribe (contact_id)');
});

        $this->ifIndexMissing('email_unsubscribe', 'idx_unsubscribe_email', function (): void {
    $this->addSql('CREATE INDEX idx_unsubscribe_email ON email_unsubscribe (email)');
});


        // Add new columns to email_campaigns
        $this->ifColumnMissing('email_campaigns', 'send_time_optimization', function (): void {
    $this->addSql('ALTER TABLE email_campaigns ADD COLUMN send_time_optimization BOOLEAN NOT NULL DEFAULT 0');
});

        $this->ifColumnMissing('email_campaigns', 'ab_test_variants', function (): void {
    $this->addSql('ALTER TABLE email_campaigns ADD COLUMN ab_test_variants CLOB DEFAULT NULL');
});


        // Create missing entities tables
        if (!$this->hasSchema()) {
        $this->addSql('CREATE TABLE IF NOT EXISTS bom_line ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, quote_id INTEGER NOT NULL, line_number INTEGER NOT NULL, mpn VARCHAR(255) DEFAULT NULL, manufacturer VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, quantity INTEGER NOT NULL, unit_price NUMERIC(10, 4) DEFAULT NULL, extended_price NUMERIC(12, 2) DEFAULT NULL, procurement_source VARCHAR(50) DEFAULT NULL, has_exception TINYINT(1) DEFAULT NULL, exception_reason VARCHAR(100) DEFAULT NULL, lead_time_days INTEGER DEFAULT NULL, availability VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_bom_line_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) )');
        }

        if (!$this->hasSchema()) {
        $this->ifIndexMissing('bom_lines', 'idx_bom_quote', function (): void {
    $this->addSql('CREATE INDEX idx_bom_quote ON bom_lines (quote_id)');
});
        }

        if (!$this->hasSchema()) {
        $this->ifIndexMissing('bom_lines', 'idx_bom_mpn', function (): void {
    $this->addSql('CREATE INDEX idx_bom_mpn ON bom_lines (mpn)');
});
        }


        $this->addSql('CREATE TABLE IF NOT EXISTS procurement_exception ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, bom_line_id INTEGER NOT NULL, exception_type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, recommendation LONGTEXT DEFAULT NULL, metadata LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_proc_exc_bomline FOREIGN KEY (bom_line_id) REFERENCES bom_lines (id) )');

        $this->ifIndexMissing('procurement_exception', 'idx_proc_exc_bomline', function (): void {
    $this->addSql('CREATE INDEX idx_proc_exc_bomline ON procurement_exception (bom_line_id)');
});

        $this->ifIndexMissing('procurement_exception', 'idx_proc_exc_severity', function (): void {
    $this->addSql('CREATE INDEX idx_proc_exc_severity ON procurement_exception (severity)');
});


        $this->addSql('CREATE TABLE IF NOT EXISTS dfm_finding ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, quote_id INTEGER NOT NULL, dfm_rule_id INTEGER DEFAULT NULL, finding_type VARCHAR(100) NOT NULL, severity VARCHAR(20) NOT NULL, description LONGTEXT NOT NULL, remediation LONGTEXT DEFAULT NULL, cost_impact NUMERIC(10, 2) DEFAULT NULL, lead_time_impact INTEGER DEFAULT NULL, metadata LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_dfm_finding_quote FOREIGN KEY (quote_id) REFERENCES quotes (id), CONSTRAINT FK_dfm_finding_rule FOREIGN KEY (dfm_rule_id) REFERENCES dfm_rules (id) )');

        $this->ifIndexMissing('dfm_finding', 'idx_dfm_quote', function (): void {
    $this->addSql('CREATE INDEX idx_dfm_quote ON dfm_finding (quote_id)');
});

        $this->ifIndexMissing('dfm_finding', 'idx_dfm_severity', function (): void {
    $this->addSql('CREATE INDEX idx_dfm_severity ON dfm_finding (severity)');
});


        $this->addSql('CREATE TABLE IF NOT EXISTS abm_account ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, account_name VARCHAR(255) NOT NULL, domain VARCHAR(255) NOT NULL, icp_tier VARCHAR(20) DEFAULT NULL, engagement_score INTEGER DEFAULT NULL, total_visits INTEGER DEFAULT NULL, total_page_views INTEGER DEFAULT NULL, first_seen_at DATETIME DEFAULT NULL, last_activity_at DATETIME DEFAULT NULL, metadata LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL )');

        $this->ifIndexMissing('abm_account', 'uniq_abm_domain', function (): void {
    $this->addSql('CREATE UNIQUE INDEX uniq_abm_domain ON abm_account (domain)');
});

        $this->ifIndexMissing('abm_account', 'idx_abm_domain', function (): void {
    $this->addSql('CREATE INDEX idx_abm_domain ON abm_account (domain)');
});

        $this->ifIndexMissing('abm_account', 'idx_abm_icp_tier', function (): void {
    $this->addSql('CREATE INDEX idx_abm_icp_tier ON abm_account (icp_tier)');
});

        $this->ifIndexMissing('abm_account', 'idx_abm_engagement', function (): void {
    $this->addSql('CREATE INDEX idx_abm_engagement ON abm_account (engagement_score)');
});


        $this->addSql('CREATE TABLE IF NOT EXISTS dataset_version ( id INT AUTO_INCREMENT PRIMARY KEY NOT NULL, dataset_type VARCHAR(100) NOT NULL, version_uuid VARCHAR(36) NOT NULL, sha256_hash VARCHAR(64) NOT NULL, record_count INTEGER DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, metadata LONGTEXT DEFAULT NULL, imported_at DATETIME NOT NULL, imported_by VARCHAR(100) DEFAULT NULL )');

        $this->ifIndexMissing('dataset_version', 'uniq_dataset_uuid', function (): void {
    $this->addSql('CREATE UNIQUE INDEX uniq_dataset_uuid ON dataset_version (version_uuid)');
});

        $this->ifIndexMissing('dataset_version', 'idx_dataset_type', function (): void {
    $this->addSql('CREATE INDEX idx_dataset_type ON dataset_version (dataset_type)');
});

        $this->ifIndexMissing('dataset_version', 'idx_dataset_active', function (): void {
    $this->addSql('CREATE INDEX idx_dataset_active ON dataset_version (is_active)');
});

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

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetchOne();
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )->fetchOne();
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        )->fetchOne();
    }

    private function hasSchema(): bool
    {
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'doctrine_migration_versions'"
        );
        return $count > 0;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $constraint]
        )->fetchOne();
    }

    private function ifConstraintMissing(string $table, string $constraint, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->constraintExists($table, $constraint)) {
            return;
        }
        $fn();
    }

    private function ifColumnMissing(string $table, string $column, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }
        $fn();
    }

    private function ifIndexMissing(string $table, string $index, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->indexExists($table, $index)) {
            return;
        }
        $fn();
    }

    private function ifIndexExists(string $table, string $index, callable $fn): void
    {
        if ($this->tableExists($table) && $this->indexExists($table, $index)) {
            $fn();
        }
    }

    private function ifTableEmpty(string $table, callable $fn): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `{$table}`");
        if ($count === 0) {
            $fn();
        }
    }
}
