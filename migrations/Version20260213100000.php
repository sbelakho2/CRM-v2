<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CompCrawler: Create competitor, competitor_change_events, competitor_block_intel,
 * competitor_page_fingerprints, and competitor_watchlists tables.
 */
final class Version20260213100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CompCrawler — create competitors, competitor_change_events, competitor_block_intel, competitor_page_fingerprints, competitor_watchlists tables';
    }

    public function up(Schema $schema): void
    {
        // This migration only ever created the legacy CompCrawler table family
        // (competitors, competitor_watchlists, ...) whose entities were
        // removed from the application. On any schema created from the
        // baseline (or production), those tables must not exist, so this
        // migration is a complete no-op.
        if ($this->hasSchema()) {
            return;
        }

        // ── competitors ──
        $this->addSql('CREATE TABLE IF NOT EXISTS competitors ( id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, aliases JSON DEFAULT NULL , canonical_domain VARCHAR(255) NOT NULL, alt_domains JSON DEFAULT NULL , competitor_types JSON DEFAULT NULL , directness VARCHAR(30) DEFAULT \'direct\' NOT NULL, status VARCHAR(30) DEFAULT \'candidate\' NOT NULL, regions JSON DEFAULT NULL , facilities JSON DEFAULT NULL , capabilities JSON DEFAULT NULL , certifications JSON DEFAULT NULL , industries JSON DEFAULT NULL , positioning_claims JSON DEFAULT NULL , customer_claims JSON DEFAULT NULL , hiring_signals JSON DEFAULT NULL , equipment_hints JSON DEFAULT NULL , contacts_public JSON DEFAULT NULL , languages_supported JSON DEFAULT NULL , machining_profile JSON DEFAULT NULL , harness_profile JSON DEFAULT NULL , supercap_profile JSON DEFAULT NULL , evidence_families_passed JSON DEFAULT NULL , evidence_urls JSON DEFAULT NULL , verification_trace JSON DEFAULT NULL , threat_score INT DEFAULT 0 NOT NULL, overlap_score INT DEFAULT 0 NOT NULL, strategic_relevance_score INT DEFAULT 0 NOT NULL, proof_grade VARCHAR(5) DEFAULT NULL, parent_competitor_id INT DEFAULT NULL, relationship_graph JSON DEFAULT NULL , raw_extract LONGTEXT DEFAULT NULL, raw_extract_expires_at DATETIME DEFAULT NULL, discovery_source VARCHAR(50) DEFAULT NULL, seed_only TINYINT(1) DEFAULT 0 NOT NULL, tos_blocks_crawl TINYINT(1) DEFAULT 0 NOT NULL, last_shallow_crawl_at DATETIME DEFAULT NULL, last_deep_crawl_at DATETIME DEFAULT NULL, last_crawled_at DATETIME DEFAULT NULL, profile_version INT DEFAULT 1 NOT NULL, pages_crawled INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_comp_domain (canonical_domain), INDEX idx_comp_status (status), INDEX idx_comp_threat (threat_score), INDEX idx_comp_overlap (overlap_score), INDEX idx_comp_directness (directness), INDEX idx_comp_parent (parent_competitor_id), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');


        if (!$this->hasSchema()) {
        $this->ifConstraintMissing('competitors', 'FK_comp_parent', function (): void {
    $this->addSql('ALTER TABLE competitors ADD CONSTRAINT FK_comp_parent FOREIGN KEY (parent_competitor_id) REFERENCES competitors (id) ON DELETE SET NULL');
});
        }


        // ── competitor_change_events ──
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_change_events ( id INT AUTO_INCREMENT NOT NULL, competitor_id INT NOT NULL, change_type VARCHAR(60) NOT NULL, severity VARCHAR(10) DEFAULT \'low\' NOT NULL, diff_summary LONGTEXT DEFAULT NULL, evidence_urls JSON DEFAULT NULL , old_value JSON DEFAULT NULL , new_value JSON DEFAULT NULL , created_at DATETIME NOT NULL, INDEX idx_cce_competitor (competitor_id), INDEX idx_cce_type (change_type), INDEX idx_cce_severity (severity), INDEX idx_cce_created (created_at), PRIMARY KEY(id), CONSTRAINT FK_cce_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');


        // ── competitor_block_intel (feedback to LeadCrawler) ──
        if (!$this->hasSchema()) {
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_block_intel ( id INT AUTO_INCREMENT NOT NULL, source_competitor_id INT DEFAULT NULL, blocked_domains_add JSON DEFAULT NULL , competitor_phrases_add JSON DEFAULT NULL , embedding_examples_add JSON DEFAULT NULL , synced_to_leadcrawler TINYINT(1) DEFAULT 0 NOT NULL, generated_at DATETIME NOT NULL, synced_at DATETIME DEFAULT NULL, INDEX idx_cbi_source (source_competitor_id), INDEX idx_cbi_synced (synced_to_leadcrawler), PRIMARY KEY(id), CONSTRAINT FK_cbi_competitor FOREIGN KEY (source_competitor_id) REFERENCES competitors (id) ON DELETE SET NULL ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }


        // ── competitor_page_fingerprints (change detection) ──
        if (!$this->hasSchema()) {
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_page_fingerprints ( id INT AUTO_INCREMENT NOT NULL, competitor_id INT NOT NULL, url VARCHAR(2048) NOT NULL, url_hash VARCHAR(64) NOT NULL, content_hash VARCHAR(64) NOT NULL, last_modified VARCHAR(255) DEFAULT NULL, etag VARCHAR(255) DEFAULT NULL, extracted_facts JSON DEFAULT NULL , page_type VARCHAR(50) DEFAULT NULL, last_checked_at DATETIME NOT NULL, first_seen_at DATETIME NOT NULL, INDEX idx_cpf_competitor (competitor_id), INDEX idx_cpf_urlhash (url_hash), INDEX idx_cpf_checked (last_checked_at), PRIMARY KEY(id), UNIQUE KEY uk_cpf_comp_url (competitor_id, url_hash), CONSTRAINT FK_cpf_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }


        // ── competitor_watchlists ──
        if (!$this->hasSchema()) {
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_watchlists ( id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, region_filter VARCHAR(50) DEFAULT NULL, sector_filter VARCHAR(100) DEFAULT NULL, competitor_type_filter VARCHAR(60) DEFAULT NULL, alert_on_change TINYINT(1) DEFAULT 1 NOT NULL, auto_generated TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }


        // ── join table: competitor_watchlist_members ──
        if (!$this->hasSchema()) {
        $this->addSql('CREATE TABLE IF NOT EXISTS competitor_watchlist_members ( watchlist_id INT NOT NULL, competitor_id INT NOT NULL, INDEX IDX_cwm_watchlist (watchlist_id), INDEX IDX_cwm_competitor (competitor_id), PRIMARY KEY(watchlist_id, competitor_id), CONSTRAINT FK_cwm_watchlist FOREIGN KEY (watchlist_id) REFERENCES competitor_watchlists (id) ON DELETE CASCADE, CONSTRAINT FK_cwm_competitor FOREIGN KEY (competitor_id) REFERENCES competitors (id) ON DELETE CASCADE ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS competitor_watchlist_members');
        $this->addSql('DROP TABLE IF EXISTS competitor_watchlists');
        $this->addSql('DROP TABLE IF EXISTS competitor_page_fingerprints');
        $this->addSql('DROP TABLE IF EXISTS competitor_block_intel');
        $this->addSql('DROP TABLE IF EXISTS competitor_change_events');
        $this->addSql('DROP TABLE IF EXISTS competitors');
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
