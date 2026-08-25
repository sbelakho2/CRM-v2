<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full baseline schema, derived from the Doctrine entity metadata.
 *
 * This is the canonical starting point for fresh installs: it creates every
 * table exactly as the entities declare it (including foreign-key
 * constraints), so the entire historical migration chain (which was generated
 * against a legacy SQLite-era schema and evolved the production database in
 * place) replays as guarded no-ops on top of it. On existing production
 * databases every statement is a no-op: CREATE TABLE IF NOT EXISTS, guarded
 * ADD CONSTRAINT, and nothing is ever dropped or altered.
 *
 * Statements execute inline (they cannot be queued: the guards used by later
 * migrations must observe this schema as it is built).
 */
final class Version20251028000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline schema from entity metadata (fresh-install starting point)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS coo_supplier_decls (id INT AUTO_INCREMENT NOT NULL, supplier_name VARCHAR(255) NOT NULL, mpn VARCHAR(255) NOT NULL, country_of_origin VARCHAR(2) NOT NULL, hts_code VARCHAR(100) DEFAULT NULL, evidence_url LONGTEXT DEFAULT NULL, declared_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, certificate_number VARCHAR(100) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, is_verified TINYINT(1) NOT NULL, verified_by VARCHAR(255) DEFAULT NULL, INDEX idx_supplier_mpn (supplier_name, mpn), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS playbook_runs (id INT AUTO_INCREMENT NOT NULL, playbook_id INT NOT NULL, abm_hit_id INT DEFAULT NULL, triggered_at DATETIME NOT NULL, status VARCHAR(50) NOT NULL, completed_at DATETIME DEFAULT NULL, execution_log LONGTEXT DEFAULT NULL, tasks_created INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, INDEX IDX_7C70D89C2DB77442 (playbook_id), INDEX IDX_7C70D89C3C9131CC (abm_hit_id), INDEX idx_playbook_abm_hit (playbook_id, abm_hit_id), INDEX idx_playbook_runs_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS rfq_versions (id INT AUTO_INCREMENT NOT NULL, rfq_id INT NOT NULL, version_number INT NOT NULL, revision_code VARCHAR(50) DEFAULT NULL, revision_reason LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL, estimated_value NUMERIC(15, 2) DEFAULT NULL, line_items_snapshot JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', technical_scope LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, valid_until DATE DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, INDEX IDX_C35B7AC5ABD9545F (rfq_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS company_canonicals (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, domain VARCHAR(255) NOT NULL, alias VARCHAR(255) DEFAULT NULL, is_primary TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, INDEX idx_domain (domain), INDEX idx_company_id (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS rfqs (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, contact_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, rfq_number VARCHAR(100) DEFAULT NULL, rfq_date DATE DEFAULT NULL, type VARCHAR(50) NOT NULL, nda_sent TINYINT(1) DEFAULT 0 NOT NULL, nda_date DATE DEFAULT NULL, nda_executed TINYINT(1) DEFAULT 0 NOT NULL, status VARCHAR(50) NOT NULL, estimated_value NUMERIC(15, 2) DEFAULT NULL, currency VARCHAR(10) DEFAULT NULL, volume_annual INT DEFAULT NULL, sop_date DATE DEFAULT NULL, technical_scope LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, loss_reason VARCHAR(50) DEFAULT NULL, loss_reason_detail LONGTEXT DEFAULT NULL, competitor_won VARCHAR(255) DEFAULT NULL, winning_bid_amount NUMERIC(15, 2) DEFAULT NULL, lessons_learned LONGTEXT DEFAULT NULL, win_factors LONGTEXT DEFAULT NULL, decision_date DATE DEFAULT NULL, award_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_530068A8979B1AD6 (company_id), INDEX IDX_530068A8E7A1254A (contact_id), INDEX IDX_530068A855458D (lead_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS compliance_document_versions (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, version_number INT NOT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, mime_type VARCHAR(100) DEFAULT NULL, expiry_date DATE DEFAULT NULL, status VARCHAR(100) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, uploaded_by VARCHAR(100) DEFAULT NULL, uploaded_at DATETIME NOT NULL, approved_by VARCHAR(100) DEFAULT NULL, approved_at DATETIME DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, is_current TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_A53B08EFC33F7837 (document_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS email_sends (id INT AUTO_INCREMENT NOT NULL, campaign_id INT NOT NULL, contact_id INT NOT NULL, touch_number INT NOT NULL, sent_at DATETIME DEFAULT NULL, opened TINYINT(1) DEFAULT 0 NOT NULL, clicked TINYINT(1) DEFAULT 0 NOT NULL, replied TINYINT(1) DEFAULT 0 NOT NULL, bounced TINYINT(1) DEFAULT 0 NOT NULL, variant VARCHAR(50) DEFAULT NULL, email_address VARCHAR(255) DEFAULT NULL, status VARCHAR(20) DEFAULT \'queued\' NOT NULL, scheduled_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, clicked_at DATETIME DEFAULT NULL, retry_count INT DEFAULT 0 NOT NULL, failure_reason LONGTEXT DEFAULT NULL, INDEX idx_email_sends_campaign (campaign_id), INDEX idx_email_sends_contact (contact_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS webinar_attendees (id INT AUTO_INCREMENT NOT NULL, webinar_id INT NOT NULL, contact_id INT DEFAULT NULL, company_id INT DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, registered_at DATETIME NOT NULL, attended TINYINT(1) DEFAULT 0 NOT NULL, follow_up_sent TINYINT(1) DEFAULT 0 NOT NULL, INDEX IDX_819128FAA391D86E (webinar_id), INDEX IDX_819128FAE7A1254A (contact_id), INDEX IDX_819128FA979B1AD6 (company_id), UNIQUE INDEX uniq_webinar_email (webinar_id, email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS personalization_archetypes (id INT AUTO_INCREMENT NOT NULL, archetype_name VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, target_industry VARCHAR(50) NOT NULL, target_role VARCHAR(50) NOT NULL, preferred_tone VARCHAR(50) NOT NULL, preferred_content VARCHAR(50) NOT NULL, preferred_style VARCHAR(50) NOT NULL, feature_embedding JSON NOT NULL COMMENT \'(DC2Type:json)\', synthetic_engagement_score INT DEFAULT 75 NOT NULL, topic_interests JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, INDEX idx_archetype_industry (target_industry), INDEX idx_archetype_role (target_role), UNIQUE INDEX unique_archetype_name (archetype_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS supplier_portals (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, registered TINYINT(1) DEFAULT 0 NOT NULL, registration_date DATE DEFAULT NULL, portal_username VARCHAR(255) DEFAULT NULL, profile_completed TINYINT(1) DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, portal_url VARCHAR(500) DEFAULT NULL, portal_id VARCHAR(255) DEFAULT NULL, submitted_date DATE DEFAULT NULL, approval_date DATE DEFAULT NULL, buyer_name VARCHAR(255) DEFAULT NULL, buyer_email VARCHAR(255) DEFAULT NULL, buyer_contacted TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_27DDE79B979B1AD6 (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS web_events (id INT AUTO_INCREMENT NOT NULL, timestamp DATETIME NOT NULL, ip_address VARCHAR(45) NOT NULL, url LONGTEXT NOT NULL, method VARCHAR(10) NOT NULL, status_code INT DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, referer LONGTEXT DEFAULT NULL, is_processed TINYINT(1) NOT NULL, processed_at DATETIME DEFAULT NULL, INDEX idx_timestamp_ip (timestamp, ip_address), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS report_definitions (id INT AUTO_INCREMENT NOT NULL, created_by_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, report_type VARCHAR(50) NOT NULL, data_source VARCHAR(50) NOT NULL, columns JSON NOT NULL COMMENT \'(DC2Type:json)\', filters JSON NOT NULL COMMENT \'(DC2Type:json)\', group_by JSON NOT NULL COMMENT \'(DC2Type:json)\', order_by JSON NOT NULL COMMENT \'(DC2Type:json)\', chart_config JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', date_range_preset VARCHAR(50) DEFAULT NULL, date_field VARCHAR(100) DEFAULT NULL, custom_date_start DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', custom_date_end DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', record_limit INT DEFAULT NULL, is_public TINYINT(1) NOT NULL, is_favorite TINYINT(1) NOT NULL, category VARCHAR(100) DEFAULT NULL, access_roles JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', scheduled_delivery JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', run_count INT DEFAULT NULL, INDEX IDX_22760ECAB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS portal_candidates (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, portal_url LONGTEXT NOT NULL, status VARCHAR(50) NOT NULL, discovered_at DATETIME NOT NULL, evidence_snapshot LONGTEXT DEFAULT NULL, has_robots_txt TINYINT(1) NOT NULL, requires_manual_submit TINYINT(1) NOT NULL, tos_url LONGTEXT DEFAULT NULL, tos_reviewed TINYINT(1) NOT NULL, approved_at DATETIME DEFAULT NULL, approved_by VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_DB6F19DA979B1AD6 (company_id), INDEX idx_company_status (company_id, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS dfm_finding (id INT AUTO_INCREMENT NOT NULL, quote_id INT NOT NULL, dfm_rule_id INT DEFAULT NULL, finding_type VARCHAR(100) NOT NULL, severity VARCHAR(20) NOT NULL, description LONGTEXT DEFAULT NULL, remediation LONGTEXT DEFAULT NULL, cost_impact NUMERIC(10, 2) DEFAULT NULL, lead_time_impact INT DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, INDEX IDX_4C2C6BB09F7B7BD7 (dfm_rule_id), INDEX idx_dfm_quote (quote_id), INDEX idx_dfm_severity (severity), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS fx_rates (id INT AUTO_INCREMENT NOT NULL, from_currency VARCHAR(10) NOT NULL, to_currency VARCHAR(10) NOT NULL, rate NUMERIC(12, 6) NOT NULL, asof DATETIME NOT NULL, version_id VARCHAR(36) NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_currencies (from_currency, to_currency), INDEX idx_asof (asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS personalization_profiles (id INT AUTO_INCREMENT NOT NULL, contact_id INT DEFAULT NULL, company_id INT DEFAULT NULL, preferred_tone VARCHAR(50) NOT NULL, preferred_content VARCHAR(50) NOT NULL, preferred_style VARCHAR(50) NOT NULL, topic_interests JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', avoid_topics JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', feature_embedding JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', interaction_history JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', emails_opened INT NOT NULL, emails_replied INT NOT NULL, emails_bounced INT NOT NULL, links_clicked INT NOT NULL, best_send_time VARCHAR(50) DEFAULT NULL, best_send_day VARCHAR(10) DEFAULT NULL, successful_subject_patterns JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_pers_company (company_id), UNIQUE INDEX uniq_pers_contact (contact_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS nre_tables (id INT AUTO_INCREMENT NOT NULL, service_type VARCHAR(100) NOT NULL, flat_fee NUMERIC(15, 4) NOT NULL, currency VARCHAR(3) NOT NULL, asof DATETIME NOT NULL, version_id VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_service_type_asof (service_type, asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS email_campaigns (id INT AUTO_INCREMENT NOT NULL, template_id INT DEFAULT NULL, segment_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, language VARCHAR(10) NOT NULL, description LONGTEXT DEFAULT NULL, touch_count INT NOT NULL, touch_templates JSON NOT NULL COMMENT \'(DC2Type:json)\', ab_test_variants JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', active TINYINT(1) NOT NULL, subject VARCHAR(255) DEFAULT NULL, from_name VARCHAR(255) DEFAULT NULL, from_email VARCHAR(255) DEFAULT NULL, body_html LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'draft\' NOT NULL, type VARCHAR(20) DEFAULT NULL, trigger_type VARCHAR(50) DEFAULT NULL, trigger_conditions JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', send_time_optimization TINYINT(1) DEFAULT 0 NOT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, scheduled_at DATETIME DEFAULT NULL, INDEX IDX_EC78EB5B5DA0FB8 (template_id), INDEX IDX_EC78EB5BDB296AAD (segment_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL COMMENT \'(DC2Type:json)\', password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, role VARCHAR(50) DEFAULT NULL, territory VARCHAR(100) DEFAULT NULL, active TINYINT(1) NOT NULL, is_verified TINYINT(1) DEFAULT 0 NOT NULL, reset_token VARCHAR(100) DEFAULT NULL, reset_token_expires_at DATETIME DEFAULT NULL, display_currency VARCHAR(10) DEFAULT NULL, preferred_locale VARCHAR(10) DEFAULT NULL, preferred_timezone VARCHAR(64) DEFAULT NULL, preferred_theme VARCHAR(20) DEFAULT NULL, accent_color VARCHAR(20) DEFAULT NULL, font_size VARCHAR(20) DEFAULT NULL, density VARCHAR(20) DEFAULT NULL, reduced_motion TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS abm_hits (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, abm_account_id INT DEFAULT NULL, timestamp DATETIME NOT NULL, ip_address VARCHAR(45) NOT NULL, organization_name VARCHAR(255) DEFAULT NULL, url_visited LONGTEXT DEFAULT NULL, session_duration INT DEFAULT NULL, page_views INT DEFAULT NULL, firmographic_data JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', is_identified TINYINT(1) NOT NULL, playbook_triggered TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_50A8D529979B1AD6 (company_id), INDEX IDX_50A8D5298B9C0B45 (abm_account_id), INDEX idx_company_timestamp (company_id, timestamp), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS custom_field_values (id INT AUTO_INCREMENT NOT NULL, field_definition_id INT NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id INT NOT NULL, text_value LONGTEXT DEFAULT NULL, number_value NUMERIC(20, 6) DEFAULT NULL, date_value DATE DEFAULT NULL, datetime_value DATETIME DEFAULT NULL, boolean_value TINYINT(1) DEFAULT NULL, json_value JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_6B64D7FF4D0FDD48 (field_definition_id), INDEX idx_custom_value_entity (entity_type, entity_id), UNIQUE INDEX unique_field_value (field_definition_id, entity_type, entity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS custom_field_definitions (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, field_key VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, entity_type VARCHAR(50) NOT NULL, field_type VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, placeholder VARCHAR(255) DEFAULT NULL, default_value VARCHAR(255) DEFAULT NULL, is_required TINYINT(1) NOT NULL, is_active TINYINT(1) NOT NULL, is_searchable TINYINT(1) NOT NULL, show_in_list TINYINT(1) NOT NULL, show_in_detail TINYINT(1) NOT NULL, sort_order INT NOT NULL, field_group VARCHAR(100) DEFAULT NULL, validation_rules JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', options JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', config JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_A4967298B03A8386 (created_by_id), INDEX idx_custom_field_entity (entity_type), UNIQUE INDEX unique_field_entity (field_key, entity_type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS webinars (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, language VARCHAR(10) NOT NULL, scheduled_date DATETIME NOT NULL, duration INT NOT NULL, description LONGTEXT DEFAULT NULL, registration_url VARCHAR(500) DEFAULT NULL, recording_url VARCHAR(500) DEFAULT NULL, meeting_url VARCHAR(500) DEFAULT NULL, registered_count INT DEFAULT 0 NOT NULL, attended_count INT DEFAULT 0 NOT NULL, max_attendees INT DEFAULT NULL, status VARCHAR(50) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS case_studies (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, sector VARCHAR(100) NOT NULL, challenge LONGTEXT DEFAULT NULL, solution LONGTEXT DEFAULT NULL, results LONGTEXT DEFAULT NULL, published TINYINT(1) DEFAULT 0 NOT NULL, anonymized TINYINT(1) DEFAULT 0 NOT NULL, language VARCHAR(5) NOT NULL, pdf_path VARCHAR(500) DEFAULT NULL, published_at DATETIME DEFAULT NULL, INDEX IDX_6C0AEF34979B1AD6 (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS dataset_version (id INT AUTO_INCREMENT NOT NULL, dataset_type VARCHAR(100) NOT NULL, version_uuid VARCHAR(36) NOT NULL, sha256_hash VARCHAR(64) NOT NULL, record_count INT DEFAULT NULL, is_active TINYINT(1) DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', imported_at DATETIME NOT NULL, imported_by VARCHAR(100) DEFAULT NULL, UNIQUE INDEX UNIQ_94CEFF85606F4CA2 (version_uuid), INDEX idx_dataset_type (dataset_type), INDEX idx_dataset_active (is_active), INDEX idx_dataset_uuid (version_uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS route_preferences (id INT AUTO_INCREMENT NOT NULL, destination_country VARCHAR(2) NOT NULL, lane_code VARCHAR(100) NOT NULL, `rank` INT NOT NULL, origin_port VARCHAR(100) DEFAULT NULL, destination_port VARCHAR(100) DEFAULT NULL, mode VARCHAR(50) NOT NULL, weight_threshold_kg NUMERIC(10, 2) DEFAULT NULL, volume_threshold_m3 NUMERIC(10, 2) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, INDEX idx_destination_country (destination_country), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS tasks (id INT AUTO_INCREMENT NOT NULL, assigned_to_id INT DEFAULT NULL, created_by_id INT NOT NULL, company_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, rfq_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, priority VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, due_date DATE DEFAULT NULL, due_time TIME DEFAULT NULL, estimated_minutes INT DEFAULT NULL, actual_minutes INT DEFAULT NULL, reminder_at DATETIME DEFAULT NULL, reminder_sent TINYINT(1) NOT NULL, is_recurring TINYINT(1) NOT NULL, recurring_frequency VARCHAR(20) DEFAULT NULL, tags JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', sort_order INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, INDEX IDX_50586597B03A8386 (created_by_id), INDEX IDX_50586597979B1AD6 (company_id), INDEX IDX_50586597E7A1254A (contact_id), INDEX IDX_50586597ABD9545F (rfq_id), INDEX IDX_5058659755458D (lead_id), INDEX idx_task_status (status), INDEX idx_task_due_date (due_date), INDEX idx_task_assigned (assigned_to_id), INDEX idx_task_priority (priority), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS contacts (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, job_title VARCHAR(100) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(50) DEFAULT NULL, linked_in_url VARCHAR(2048) DEFAULT NULL, source VARCHAR(50) DEFAULT NULL, primary_contact TINYINT(1) DEFAULT 0 NOT NULL, notes LONGTEXT DEFAULT NULL, role VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_33401573979B1AD6 (company_id), INDEX idx_contact_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS contact_email_campaigns (contact_id INT NOT NULL, email_campaign_id INT NOT NULL, INDEX IDX_776FD465E7A1254A (contact_id), INDEX IDX_776FD465E0F98BC3 (email_campaign_id), PRIMARY KEY(contact_id, email_campaign_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS playbooks (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, trigger_rules LONGTEXT DEFAULT NULL, actions LONGTEXT DEFAULT NULL, priority INT DEFAULT NULL, is_active TINYINT(1) NOT NULL, cooldown_hours INT DEFAULT 24, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS abm_account (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, account_name VARCHAR(255) NOT NULL, domain VARCHAR(255) NOT NULL, icp_tier VARCHAR(20) DEFAULT NULL, engagement_score INT DEFAULT NULL, total_visits INT DEFAULT NULL, total_page_views INT DEFAULT NULL, first_seen_at DATETIME DEFAULT NULL, last_activity_at DATETIME DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_EEBF221AA7A91E0B (domain), INDEX IDX_EEBF221A979B1AD6 (company_id), INDEX idx_abm_domain (domain), INDEX idx_abm_icp_tier (icp_tier), INDEX idx_abm_engagement (engagement_score), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS hts_map_rules (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(100) NOT NULL, keywords JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', hts_code VARCHAR(100) NOT NULL, confidence VARCHAR(50) NOT NULL, heuristic_logic LONGTEXT DEFAULT NULL, priority INT NOT NULL, notes LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_category_keywords (category), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS competitor_detection (id INT AUTO_INCREMENT NOT NULL, lead_id INT NOT NULL, competitor_domain VARCHAR(255) NOT NULL, competitor_name VARCHAR(255) NOT NULL, competitor_tier SMALLINT NOT NULL, detected_in VARCHAR(100) NOT NULL, detection_confidence SMALLINT NOT NULL, INDEX IDX_18EA4AA355458D (lead_id), INDEX idx_competitor_domain (competitor_domain), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS email_unsubscribe (id INT AUTO_INCREMENT NOT NULL, contact_id INT DEFAULT NULL, email VARCHAR(255) NOT NULL, reason VARCHAR(100) DEFAULT NULL, feedback_text LONGTEXT DEFAULT NULL, unsubscribed_at DATETIME NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, INDEX idx_unsubscribe_contact (contact_id), INDEX idx_unsubscribe_email (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS email_segment (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, filter_rules_json JSON NOT NULL COMMENT \'(DC2Type:json)\', contact_count INT NOT NULL, is_active TINYINT(1) NOT NULL, last_calculated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, INDEX idx_segment_name (name), INDEX idx_segment_active (is_active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS report_audits (id INT AUTO_INCREMENT NOT NULL, report_type VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id INT NOT NULL, sha256_hash VARCHAR(64) NOT NULL, version_id VARCHAR(100) DEFAULT NULL, dataset_versions JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', api_versions JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', file_name VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, generated_by VARCHAR(100) DEFAULT NULL, generated_at DATETIME NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_report_type (report_type), INDEX idx_entity_type_id (entity_type, entity_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS companies (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sector VARCHAR(100) DEFAULT NULL, account_tier VARCHAR(10) NOT NULL, region VARCHAR(100) DEFAULT NULL, pipeline_stage VARCHAR(50) NOT NULL, company_status VARCHAR(30) DEFAULT \'approved\' NOT NULL, website VARCHAR(2048) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, country VARCHAR(50) DEFAULT NULL, linked_in_url VARCHAR(2048) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, physical_site VARCHAR(255) DEFAULT NULL, linkedin_company_url VARCHAR(500) DEFAULT NULL, source_notes LONGTEXT DEFAULT NULL, legal_name VARCHAR(255) DEFAULT NULL, google_drive_link VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_company_name (name), INDEX idx_company_sector (sector), INDEX idx_company_pipeline (pipeline_stage), INDEX idx_company_status (company_status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS procurement_exception (id INT AUTO_INCREMENT NOT NULL, bom_line_id INT NOT NULL, exception_type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, recommendation LONGTEXT DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, INDEX idx_proc_exc_bomline (bom_line_id), INDEX idx_proc_exc_severity (severity), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS quotes (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, contact_id INT DEFAULT NULL, rfq_id INT DEFAULT NULL, quote_number VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, total_cost NUMERIC(15, 2) NOT NULL, currency VARCHAR(10) NOT NULL, public_token VARCHAR(64) DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, interactive_enabled TINYINT(1) DEFAULT 0 NOT NULL, quantity_options JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', view_count INT DEFAULT NULL, last_viewed_at DATETIME DEFAULT NULL, coverage_percent NUMERIC(5, 2) DEFAULT NULL, alibaba_percent NUMERIC(5, 2) DEFAULT NULL, critical_dfm_count INT DEFAULT NULL, max_imputed_lead_time_days INT DEFAULT NULL, auto_published TINYINT(1) NOT NULL, dataset_version_id VARCHAR(36) DEFAULT NULL, api_versions JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', bom_data_json LONGTEXT DEFAULT NULL, ship_to_country VARCHAR(100) DEFAULT NULL, incoterms VARCHAR(50) DEFAULT NULL, quantity INT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, issuing_company VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A1B588C5AC28B117 (quote_number), UNIQUE INDEX UNIQ_A1B588C5AE981E3B (public_token), INDEX idx_company (company_id), INDEX idx_quote_number (quote_number), INDEX idx_status (status), INDEX idx_public_token (public_token), INDEX idx_quotes_contact (contact_id), INDEX idx_quotes_rfq (rfq_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS tariff_rates (id INT AUTO_INCREMENT NOT NULL, hs_code VARCHAR(20) NOT NULL, origin_country VARCHAR(100) NOT NULL, destination_country VARCHAR(100) NOT NULL, duty_rate NUMERIC(5, 2) NOT NULL, mfn_rate NUMERIC(5, 2) DEFAULT NULL, fta_rate NUMERIC(5, 2) DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, notes LONGTEXT DEFAULT NULL, fta_agreement VARCHAR(100) DEFAULT NULL, version_id VARCHAR(36) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_hs_code (hs_code), INDEX idx_effective_date (effective_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS pcb_curves (id INT AUTO_INCREMENT NOT NULL, layers INT NOT NULL, area_m2 NUMERIC(12, 2) NOT NULL, cost_per_m2 NUMERIC(15, 4) NOT NULL, currency VARCHAR(3) NOT NULL, asof DATETIME NOT NULL, version_id VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_layers_asof (layers, asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS compliance_documents (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, name VARCHAR(255) NOT NULL, required TINYINT(1) NOT NULL, provided TINYINT(1) NOT NULL, status VARCHAR(100) DEFAULT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, expiry_date DATE DEFAULT NULL, uploaded_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, snoozed_until DATE DEFAULT NULL, snooze_reason VARCHAR(255) DEFAULT NULL, snoozed_by VARCHAR(100) DEFAULT NULL, INDEX IDX_EABE6873979B1AD6 (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS onboarding_packs (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, portal_candidate_id INT DEFAULT NULL, status VARCHAR(50) NOT NULL, pack_contents LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, submitted_by VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, sha256_hash VARCHAR(64) DEFAULT NULL, INDEX IDX_846C0DCB979B1AD6 (company_id), INDEX IDX_846C0DCB91D286EE (portal_candidate_id), INDEX idx_company_portal (company_id, portal_candidate_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS activities (id INT AUTO_INCREMENT NOT NULL, company_id INT NOT NULL, contact_id INT DEFAULT NULL, user_id INT NOT NULL, type VARCHAR(50) NOT NULL, subject VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, outcome VARCHAR(50) DEFAULT NULL, outcome_detail VARCHAR(50) DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, duration_minutes INT DEFAULT NULL, activity_date DATETIME NOT NULL, follow_up_date DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_B5F1AFE5E7A1254A (contact_id), INDEX IDX_B5F1AFE5A76ED395 (user_id), INDEX idx_activities_user_date (user_id, activity_date), INDEX idx_activities_company (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS bom_lines (id INT AUTO_INCREMENT NOT NULL, quote_id INT NOT NULL, line_number INT NOT NULL, mpn VARCHAR(255) DEFAULT NULL, original_mpn VARCHAR(255) DEFAULT NULL, manufacturer VARCHAR(255) DEFAULT NULL, matched_mpn VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, bom_description LONGTEXT DEFAULT NULL, quantity INT NOT NULL, unit_price NUMERIC(10, 4) DEFAULT NULL, extended_price NUMERIC(12, 2) DEFAULT NULL, procurement_source VARCHAR(50) DEFAULT NULL, has_exception TINYINT(1) DEFAULT NULL, exception_reason VARCHAR(100) DEFAULT NULL, lead_time_days INT DEFAULT NULL, availability VARCHAR(20) DEFAULT NULL, confidence_score SMALLINT DEFAULT NULL, confidence_level VARCHAR(20) DEFAULT NULL, confidence_reasons JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', confidence_warnings JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', requires_review TINYINT(1) DEFAULT NULL, manually_verified TINYINT(1) DEFAULT NULL, manual_unit_price NUMERIC(10, 4) DEFAULT NULL, manual_notes LONGTEXT DEFAULT NULL, verified_by VARCHAR(100) DEFAULT NULL, verified_at DATETIME DEFAULT NULL, lifecycle_status VARCHAR(50) DEFAULT NULL, lifecycle_warning VARCHAR(20) DEFAULT NULL, price_source_url VARCHAR(500) DEFAULT NULL, alternative_parts JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', distributor_search_url VARCHAR(500) DEFAULT NULL, supplier_name VARCHAR(255) DEFAULT NULL, supplier_product_url VARCHAR(500) DEFAULT NULL, sourcing_data JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_bom_quote (quote_id), INDEX idx_bom_mpn (mpn), INDEX idx_bom_review (requires_review), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS email_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, subject_line VARCHAR(255) NOT NULL, preview_text VARCHAR(255) DEFAULT NULL, body_html LONGTEXT NOT NULL, body_text LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, category VARCHAR(50) DEFAULT NULL, is_active TINYINT(1) NOT NULL, personalization_tokens JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, INDEX idx_template_name (name), INDEX idx_template_active (is_active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS quote_part_breakdowns (id INT AUTO_INCREMENT NOT NULL, quote_id INT NOT NULL, mpn VARCHAR(255) NOT NULL, manufacturer VARCHAR(255) DEFAULT NULL, quantity INT NOT NULL, unit_price NUMERIC(15, 4) NOT NULL, extended_price NUMERIC(15, 2) NOT NULL, data_source VARCHAR(100) DEFAULT NULL, lead_time_days INT DEFAULT NULL, is_imputed TINYINT(1) NOT NULL, category VARCHAR(100) DEFAULT NULL, INDEX IDX_88D90569DB805178 (quote_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS freight_tables (id INT AUTO_INCREMENT NOT NULL, origin_port VARCHAR(100) NOT NULL, destination_port VARCHAR(100) NOT NULL, transport_mode VARCHAR(50) NOT NULL, container_type VARCHAR(20) NOT NULL, cost_per_unit NUMERIC(10, 2) NOT NULL, currency VARCHAR(10) NOT NULL, transit_days INT DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, carrier VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, version_id VARCHAR(36) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_route (origin_port, destination_port), INDEX idx_effective_date (effective_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS ip_maps (id INT AUTO_INCREMENT NOT NULL, ip_address VARCHAR(45) NOT NULL, organization_name VARCHAR(255) DEFAULT NULL, domain VARCHAR(255) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, region VARCHAR(100) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, firmographic_data JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', asof DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, INDEX idx_ip_asof (ip_address, asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS dfm_rules (id INT AUTO_INCREMENT NOT NULL, rule_type VARCHAR(100) NOT NULL, rule_name VARCHAR(255) NOT NULL, severity VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, remediation_text LONGTEXT DEFAULT NULL, check_logic LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_rule_type_severity (rule_type, severity), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS price_history (id INT AUTO_INCREMENT NOT NULL, quote_id INT DEFAULT NULL, bom_line_id INT DEFAULT NULL, mpn VARCHAR(255) NOT NULL, matched_mpn VARCHAR(255) DEFAULT NULL, manufacturer VARCHAR(255) DEFAULT NULL, source VARCHAR(50) NOT NULL, unit_price NUMERIC(10, 4) NOT NULL, price_breaks JSON NOT NULL COMMENT \'(DC2Type:json)\', currency VARCHAR(10) NOT NULL, unit_price_usd NUMERIC(10, 4) DEFAULT NULL, stock_available INT DEFAULT NULL, lead_time_days INT DEFAULT NULL, lifecycle_status VARCHAR(50) DEFAULT NULL, confidence_score SMALLINT DEFAULT NULL, confidence_level VARCHAR(20) DEFAULT NULL, moq INT DEFAULT NULL, pack_quantity INT DEFAULT NULL, recorded_at DATETIME NOT NULL, raw_api_response JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', INDEX IDX_4C9CB817DB805178 (quote_id), INDEX IDX_4C9CB817B2EEEC4D (bom_line_id), INDEX idx_ph_mpn (mpn), INDEX idx_ph_source (source), INDEX idx_ph_date (recorded_at), INDEX idx_ph_mpn_source (mpn, source), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS packaging_factors (id INT AUTO_INCREMENT NOT NULL, category VARCHAR(100) NOT NULL, kg_per_unit NUMERIC(10, 4) NOT NULL, dm3_per_unit NUMERIC(10, 4) NOT NULL, palletization_rule_json JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', asof DATETIME NOT NULL, version_id VARCHAR(36) NOT NULL, is_active TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_category (category), INDEX idx_asof (asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS bandit_arms (id INT AUTO_INCREMENT NOT NULL, arm_type VARCHAR(50) NOT NULL, arm_name VARCHAR(255) NOT NULL, arm_value LONGTEXT NOT NULL, alpha DOUBLE PRECISION DEFAULT \'1\' NOT NULL, beta DOUBLE PRECISION DEFAULT \'1\' NOT NULL, icp_cluster VARCHAR(100) DEFAULT \'global\' NOT NULL, recent_negative_rate DOUBLE PRECISION DEFAULT \'0\' NOT NULL, quarantined TINYINT(1) DEFAULT 0 NOT NULL, is_control TINYINT(1) DEFAULT 0 NOT NULL, total_trials INT DEFAULT 0 NOT NULL, total_successes INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, INDEX idx_bandit_arm_type (arm_type), INDEX idx_bandit_active (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS leads (id INT AUTO_INCREMENT NOT NULL, company_id INT DEFAULT NULL, company_name VARCHAR(255) NOT NULL, legal_name VARCHAR(255) DEFAULT NULL, website_root VARCHAR(255) DEFAULT NULL, lead_url VARCHAR(500) DEFAULT NULL, site_location VARCHAR(255) DEFAULT NULL, us_state VARCHAR(10) DEFAULT NULL, us_city_metro VARCHAR(100) DEFAULT NULL, region_tag VARCHAR(50) DEFAULT NULL, sector_tags JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', fit_signals JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', morocco_signal TINYINT(1) DEFAULT NULL, quality_stack JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', contact_emails_public JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', contact_form_url VARCHAR(500) DEFAULT NULL, has_contact_form TINYINT(1) DEFAULT 0 NOT NULL, supplier_portal_url VARCHAR(500) DEFAULT NULL, rfq_rfp_page_url VARCHAR(500) DEFAULT NULL, supplier_portal_complexity VARCHAR(50) DEFAULT NULL, defense_flag TINYINT(1) DEFAULT NULL, last_seen DATETIME DEFAULT NULL, content_last_modified DATETIME DEFAULT NULL, lead_score INT DEFAULT NULL, notes_auto LONGTEXT DEFAULT NULL, dupe_key VARCHAR(255) DEFAULT NULL, already_in_crm TINYINT(1) DEFAULT NULL, review_status VARCHAR(50) DEFAULT NULL, deny_reason LONGTEXT DEFAULT NULL, crm_record_id VARCHAR(100) DEFAULT NULL, external_crm_url VARCHAR(500) DEFAULT NULL, owner_rep VARCHAR(100) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, source VARCHAR(255) DEFAULT NULL, scraping_method VARCHAR(20) DEFAULT NULL, nurturing_stage VARCHAR(30) DEFAULT NULL, pages_scraped INT DEFAULT NULL, last_scraped_at DATETIME DEFAULT NULL, INDEX IDX_17904552979B1AD6 (company_id), INDEX idx_leads_dupe (dupe_key), INDEX idx_leads_region (region_tag), INDEX idx_leads_score (lead_score), INDEX idx_leads_website (website_root), INDEX idx_leads_status (review_status), INDEX idx_leads_created (created_at), INDEX idx_leads_scraped (last_scraped_at), INDEX idx_leads_nurturing (nurturing_stage), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS outbound_messages (id INT AUTO_INCREMENT NOT NULL, contact_id INT NOT NULL, rfq_id INT DEFAULT NULL, subject_arm_id INT DEFAULT NULL, template_id INT DEFAULT NULL, subject LONGTEXT NOT NULL, body_text LONGTEXT NOT NULL, body_html LONGTEXT DEFAULT NULL, variation_hash VARCHAR(64) DEFAULT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, sent_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, clicked_at DATETIME DEFAULT NULL, replied_at DATETIME DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, provider VARCHAR(50) DEFAULT NULL, outcome_recorded TINYINT(1) DEFAULT 0 NOT NULL, recorded_event_type VARCHAR(20) DEFAULT NULL, reply_classification VARCHAR(30) DEFAULT NULL, reply_content LONGTEXT DEFAULT NULL, value_prop_arm_id INT DEFAULT NULL, tone_applied VARCHAR(30) DEFAULT NULL, send_time_policy VARCHAR(50) DEFAULT NULL, icp_cluster VARCHAR(100) DEFAULT NULL, is_control_group TINYINT(1) DEFAULT 0 NOT NULL, decision_trace JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', funnel_stage VARCHAR(20) DEFAULT \'pending\' NOT NULL, reply_window_expiry DATETIME DEFAULT NULL, soft_failure_applied TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_642BA0C1ABD9545F (rfq_id), INDEX IDX_642BA0C15DA0FB8 (template_id), INDEX idx_outbound_contact (contact_id), INDEX idx_outbound_arm (subject_arm_id), INDEX idx_outbound_status (status), INDEX idx_outbound_sent (sent_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS capacity_calendars (id INT AUTO_INCREMENT NOT NULL, production_date DATE NOT NULL, available_slots INT NOT NULL, booked_slots INT NOT NULL, is_holiday TINYINT(1) NOT NULL, holiday_name LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_production_date (production_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS calendar_events (id INT AUTO_INCREMENT NOT NULL, parent_event_id INT DEFAULT NULL, organizer_id INT NOT NULL, company_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, rfq_id INT DEFAULT NULL, task_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, event_type VARCHAR(50) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, all_day TINYINT(1) NOT NULL, location VARCHAR(255) DEFAULT NULL, meeting_url VARCHAR(500) DEFAULT NULL, visibility VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, color VARCHAR(7) DEFAULT NULL, is_recurring TINYINT(1) NOT NULL, recurring_frequency VARCHAR(20) DEFAULT NULL, recurring_until DATE DEFAULT NULL, recurring_count INT DEFAULT NULL, recurring_days JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', reminder_minutes INT DEFAULT NULL, reminder_sent TINYINT(1) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, external_provider VARCHAR(50) DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_F9E14F16EE3A445A (parent_event_id), INDEX IDX_F9E14F16979B1AD6 (company_id), INDEX IDX_F9E14F16E7A1254A (contact_id), INDEX IDX_F9E14F1655458D (lead_id), INDEX IDX_F9E14F16ABD9545F (rfq_id), UNIQUE INDEX UNIQ_F9E14F168DB60186 (task_id), INDEX idx_calendar_start (start_at), INDEX idx_calendar_end (end_at), INDEX idx_calendar_type (event_type), INDEX idx_calendar_organizer (organizer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS calendar_event_attendees (user_id INT NOT NULL, calendar_event_id INT NOT NULL, INDEX IDX_258586B3A76ED395 (user_id), INDEX IDX_258586B37495C8E3 (calendar_event_id), PRIMARY KEY(user_id, calendar_event_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS spintax_templates (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, template_type VARCHAR(50) DEFAULT \'email\' NOT NULL, subject_spintax LONGTEXT NOT NULL, body_spintax LONGTEXT NOT NULL, available_variables JSON NOT NULL COMMENT \'(DC2Type:json)\', times_used INT DEFAULT 0 NOT NULL, total_opens INT DEFAULT 0 NOT NULL, total_replies INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_spintax_type (template_type), INDEX idx_spintax_active (active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS sa_bayes_training (id INT AUTO_INCREMENT NOT NULL, word VARCHAR(100) NOT NULL, classification VARCHAR(30) NOT NULL, frequency INT DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL, INDEX idx_bayes_classification (classification), INDEX idx_bayes_word (word), UNIQUE INDEX unique_word_class (word, classification), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS audit_logs (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, entity_type VARCHAR(100) NOT NULL, entity_id INT NOT NULL, action VARCHAR(50) NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, old_values JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', new_values JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', changed_fields JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_entity (entity_type, entity_id), INDEX idx_user (user_id), INDEX idx_action (action), INDEX idx_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS notification (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(50) NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id INT NOT NULL, message VARCHAR(255) NOT NULL, data JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', read_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_BF5476CAA76ED395 (user_id), INDEX idx_notification_user_read (user_id, read_at), INDEX idx_notification_created (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS fta_rules (id INT AUTO_INCREMENT NOT NULL, hs_code VARCHAR(20) NOT NULL, fta_agreement VARCHAR(100) NOT NULL, roo_requirement LONGTEXT DEFAULT NULL, roo_language LONGTEXT DEFAULT NULL, minimum_value_content NUMERIC(5, 2) DEFAULT NULL, requires_certificate TINYINT(1) NOT NULL, certificate_type VARCHAR(50) DEFAULT NULL, required_documents LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, effective_date DATE NOT NULL, expiry_date DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_hs_code (hs_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS asm_curves (id INT AUTO_INCREMENT NOT NULL, component_count_min INT NOT NULL, component_count_max INT DEFAULT NULL, cost_per_unit NUMERIC(15, 4) NOT NULL, currency VARCHAR(3) NOT NULL, asof DATETIME NOT NULL, version_id VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX idx_component_count_asof (component_count_min, asof), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS rfq_line_items (id INT AUTO_INCREMENT NOT NULL, rfq_id INT NOT NULL, line_number INT NOT NULL, part_number VARCHAR(100) DEFAULT NULL, customer_part_number VARCHAR(100) DEFAULT NULL, description VARCHAR(255) NOT NULL, quantity_annual INT DEFAULT NULL, quantity_per_batch INT DEFAULT NULL, unit_price NUMERIC(15, 4) DEFAULT NULL, nre_price NUMERIC(15, 4) DEFAULT NULL, currency VARCHAR(10) DEFAULT NULL, lead_time_days INT DEFAULT NULL, technology VARCHAR(50) DEFAULT NULL, component_count INT DEFAULT NULL, requires_xray TINYINT(1) DEFAULT 0 NOT NULL, requires_aoi TINYINT(1) DEFAULT 0 NOT NULL, requires_functional_test TINYINT(1) DEFAULT 0 NOT NULL, requires_conformal_coating TINYINT(1) DEFAULT 0 NOT NULL, specifications LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, status VARCHAR(50) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_DD15496DABD9545F (rfq_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS meeting_slots (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, contact_id INT DEFAULT NULL, company_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, meeting_type VARCHAR(50) NOT NULL, duration_minutes INT NOT NULL, start_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(50) NOT NULL, location VARCHAR(255) DEFAULT NULL, meeting_url VARCHAR(500) DEFAULT NULL, meeting_provider VARCHAR(50) DEFAULT NULL, meeting_credentials JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', booked_by_name VARCHAR(255) DEFAULT NULL, booked_by_email VARCHAR(255) DEFAULT NULL, booked_by_phone VARCHAR(50) DEFAULT NULL, booked_by_company VARCHAR(255) DEFAULT NULL, booking_notes LONGTEXT DEFAULT NULL, booked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', booking_token VARCHAR(100) DEFAULT NULL, cancellation_token VARCHAR(100) DEFAULT NULL, reminder_sent TINYINT(1) NOT NULL, reminder_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', confirmation_sent TINYINT(1) NOT NULL, confirmation_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', timezone VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_A04577D0CE4B5C8E (booking_token), INDEX IDX_A04577D07E3C61F9 (owner_id), INDEX IDX_A04577D0E7A1254A (contact_id), INDEX IDX_A04577D0979B1AD6 (company_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS inbox_messages (id INT AUTO_INCREMENT NOT NULL, in_reply_to_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, from_email VARCHAR(255) NOT NULL, subject LONGTEXT DEFAULT NULL, body_text LONGTEXT DEFAULT NULL, classification VARCHAR(30) DEFAULT NULL, classification_confidence NUMERIC(5, 2) DEFAULT NULL, classification_method VARCHAR(30) DEFAULT NULL, requires_human_review TINYINT(1) DEFAULT 0 NOT NULL, human_reviewed_at DATETIME DEFAULT NULL, reviewed_by VARCHAR(255) DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', received_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_F635C51DDD92DAB8 (in_reply_to_id), INDEX IDX_F635C51DE7A1254A (contact_id), INDEX idx_inbox_classification (classification), INDEX idx_inbox_review (requires_human_review), INDEX idx_inbox_from (from_email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS learned_competitors (id INT AUTO_INCREMENT NOT NULL, domain VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, full_name VARCHAR(500) DEFAULT NULL, tier SMALLINT NOT NULL, industry VARCHAR(50) NOT NULL, discovery_source VARCHAR(50) NOT NULL, discovery_context LONGTEXT DEFAULT NULL, detection_count INT NOT NULL, confidence_score INT NOT NULL, verified TINYINT(1) NOT NULL, active TINYINT(1) NOT NULL, aliases JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', keywords JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', first_detected_at DATETIME NOT NULL, last_detected_at DATETIME NOT NULL, verified_at DATETIME DEFAULT NULL, verified_by VARCHAR(100) DEFAULT NULL, INDEX idx_learned_comp_domain (domain), INDEX idx_learned_comp_tier (tier), INDEX idx_learned_comp_active (active), INDEX idx_learned_comp_verified (verified), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        if (!$this->tableExists('playbook_runs') || $this->constraintExists('playbook_runs', 'FK_7C70D89C2DB77442')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE playbook_runs ADD CONSTRAINT FK_7C70D89C2DB77442 FOREIGN KEY (playbook_id) REFERENCES playbooks (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('playbook_runs') || $this->constraintExists('playbook_runs', 'FK_7C70D89C3C9131CC')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE playbook_runs ADD CONSTRAINT FK_7C70D89C3C9131CC FOREIGN KEY (abm_hit_id) REFERENCES abm_hits (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('rfq_versions') || $this->constraintExists('rfq_versions', 'FK_C35B7AC5ABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE rfq_versions ADD CONSTRAINT FK_C35B7AC5ABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('company_canonicals') || $this->constraintExists('company_canonicals', 'FK_514DA330979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE company_canonicals ADD CONSTRAINT FK_514DA330979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('rfqs') || $this->constraintExists('rfqs', 'FK_530068A8979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE rfqs ADD CONSTRAINT FK_530068A8979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('rfqs') || $this->constraintExists('rfqs', 'FK_530068A8E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE rfqs ADD CONSTRAINT FK_530068A8E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('rfqs') || $this->constraintExists('rfqs', 'FK_530068A855458D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE rfqs ADD CONSTRAINT FK_530068A855458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('compliance_document_versions') || $this->constraintExists('compliance_document_versions', 'FK_A53B08EFC33F7837')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE compliance_document_versions ADD CONSTRAINT FK_A53B08EFC33F7837 FOREIGN KEY (document_id) REFERENCES compliance_documents (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('email_sends') || $this->constraintExists('email_sends', 'FK_633143B3F639F774')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE email_sends ADD CONSTRAINT FK_633143B3F639F774 FOREIGN KEY (campaign_id) REFERENCES email_campaigns (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('email_sends') || $this->constraintExists('email_sends', 'FK_633143B3E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE email_sends ADD CONSTRAINT FK_633143B3E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('webinar_attendees') || $this->constraintExists('webinar_attendees', 'FK_819128FAA391D86E')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE webinar_attendees ADD CONSTRAINT FK_819128FAA391D86E FOREIGN KEY (webinar_id) REFERENCES webinars (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('webinar_attendees') || $this->constraintExists('webinar_attendees', 'FK_819128FAE7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE webinar_attendees ADD CONSTRAINT FK_819128FAE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('webinar_attendees') || $this->constraintExists('webinar_attendees', 'FK_819128FA979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE webinar_attendees ADD CONSTRAINT FK_819128FA979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('supplier_portals') || $this->constraintExists('supplier_portals', 'FK_27DDE79B979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE supplier_portals ADD CONSTRAINT FK_27DDE79B979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('report_definitions') || $this->constraintExists('report_definitions', 'FK_22760ECAB03A8386')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE report_definitions ADD CONSTRAINT FK_22760ECAB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('portal_candidates') || $this->constraintExists('portal_candidates', 'FK_DB6F19DA979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE portal_candidates ADD CONSTRAINT FK_DB6F19DA979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('dfm_finding') || $this->constraintExists('dfm_finding', 'FK_4C2C6BB0DB805178')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE dfm_finding ADD CONSTRAINT FK_4C2C6BB0DB805178 FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('dfm_finding') || $this->constraintExists('dfm_finding', 'FK_4C2C6BB09F7B7BD7')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE dfm_finding ADD CONSTRAINT FK_4C2C6BB09F7B7BD7 FOREIGN KEY (dfm_rule_id) REFERENCES dfm_rules (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('email_campaigns') || $this->constraintExists('email_campaigns', 'FK_EC78EB5B5DA0FB8')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE email_campaigns ADD CONSTRAINT FK_EC78EB5B5DA0FB8 FOREIGN KEY (template_id) REFERENCES email_template (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('email_campaigns') || $this->constraintExists('email_campaigns', 'FK_EC78EB5BDB296AAD')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE email_campaigns ADD CONSTRAINT FK_EC78EB5BDB296AAD FOREIGN KEY (segment_id) REFERENCES email_segment (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('abm_hits') || $this->constraintExists('abm_hits', 'FK_50A8D529979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE abm_hits ADD CONSTRAINT FK_50A8D529979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('abm_hits') || $this->constraintExists('abm_hits', 'FK_50A8D5298B9C0B45')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE abm_hits ADD CONSTRAINT FK_50A8D5298B9C0B45 FOREIGN KEY (abm_account_id) REFERENCES abm_account (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('custom_field_values') || $this->constraintExists('custom_field_values', 'FK_6B64D7FF4D0FDD48')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE custom_field_values ADD CONSTRAINT FK_6B64D7FF4D0FDD48 FOREIGN KEY (field_definition_id) REFERENCES custom_field_definitions (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('custom_field_definitions') || $this->constraintExists('custom_field_definitions', 'FK_A4967298B03A8386')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE custom_field_definitions ADD CONSTRAINT FK_A4967298B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('case_studies') || $this->constraintExists('case_studies', 'FK_6C0AEF34979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE case_studies ADD CONSTRAINT FK_6C0AEF34979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_50586597F4BD7827')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_50586597F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_50586597B03A8386')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_50586597B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_50586597979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_50586597979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_50586597E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_50586597E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_50586597ABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_50586597ABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('tasks') || $this->constraintExists('tasks', 'FK_5058659755458D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE tasks ADD CONSTRAINT FK_5058659755458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('contacts') || $this->constraintExists('contacts', 'FK_33401573979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE contacts ADD CONSTRAINT FK_33401573979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('contact_email_campaigns') || $this->constraintExists('contact_email_campaigns', 'FK_776FD465E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('contact_email_campaigns') || $this->constraintExists('contact_email_campaigns', 'FK_776FD465E0F98BC3')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E0F98BC3 FOREIGN KEY (email_campaign_id) REFERENCES email_campaigns (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('abm_account') || $this->constraintExists('abm_account', 'FK_EEBF221A979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE abm_account ADD CONSTRAINT FK_EEBF221A979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('competitor_detection') || $this->constraintExists('competitor_detection', 'FK_18EA4AA355458D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE competitor_detection ADD CONSTRAINT FK_18EA4AA355458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('email_unsubscribe') || $this->constraintExists('email_unsubscribe', 'FK_B3AC4CB9E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE email_unsubscribe ADD CONSTRAINT FK_B3AC4CB9E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('procurement_exception') || $this->constraintExists('procurement_exception', 'FK_86428B89B2EEEC4D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE procurement_exception ADD CONSTRAINT FK_86428B89B2EEEC4D FOREIGN KEY (bom_line_id) REFERENCES bom_lines (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('quotes') || $this->constraintExists('quotes', 'FK_A1B588C5979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE quotes ADD CONSTRAINT FK_A1B588C5979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('quotes') || $this->constraintExists('quotes', 'FK_A1B588C5E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE quotes ADD CONSTRAINT FK_A1B588C5E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('quotes') || $this->constraintExists('quotes', 'FK_A1B588C5ABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE quotes ADD CONSTRAINT FK_A1B588C5ABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('compliance_documents') || $this->constraintExists('compliance_documents', 'FK_EABE6873979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE compliance_documents ADD CONSTRAINT FK_EABE6873979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('onboarding_packs') || $this->constraintExists('onboarding_packs', 'FK_846C0DCB979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE onboarding_packs ADD CONSTRAINT FK_846C0DCB979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('onboarding_packs') || $this->constraintExists('onboarding_packs', 'FK_846C0DCB91D286EE')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE onboarding_packs ADD CONSTRAINT FK_846C0DCB91D286EE FOREIGN KEY (portal_candidate_id) REFERENCES portal_candidates (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('activities') || $this->constraintExists('activities', 'FK_B5F1AFE5979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('activities') || $this->constraintExists('activities', 'FK_B5F1AFE5E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('activities') || $this->constraintExists('activities', 'FK_B5F1AFE5A76ED395')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE activities ADD CONSTRAINT FK_B5F1AFE5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('bom_lines') || $this->constraintExists('bom_lines', 'FK_3CDE317DB805178')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE bom_lines ADD CONSTRAINT FK_3CDE317DB805178 FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('quote_part_breakdowns') || $this->constraintExists('quote_part_breakdowns', 'FK_88D90569DB805178')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE quote_part_breakdowns ADD CONSTRAINT FK_88D90569DB805178 FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('price_history') || $this->constraintExists('price_history', 'FK_4C9CB817DB805178')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE price_history ADD CONSTRAINT FK_4C9CB817DB805178 FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('price_history') || $this->constraintExists('price_history', 'FK_4C9CB817B2EEEC4D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE price_history ADD CONSTRAINT FK_4C9CB817B2EEEC4D FOREIGN KEY (bom_line_id) REFERENCES bom_lines (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('leads') || $this->constraintExists('leads', 'FK_17904552979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE leads ADD CONSTRAINT FK_17904552979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('outbound_messages') || $this->constraintExists('outbound_messages', 'FK_642BA0C1E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C1E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('outbound_messages') || $this->constraintExists('outbound_messages', 'FK_642BA0C1ABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C1ABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('outbound_messages') || $this->constraintExists('outbound_messages', 'FK_642BA0C1292D3428')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C1292D3428 FOREIGN KEY (subject_arm_id) REFERENCES bandit_arms (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('outbound_messages') || $this->constraintExists('outbound_messages', 'FK_642BA0C15DA0FB8')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C15DA0FB8 FOREIGN KEY (template_id) REFERENCES spintax_templates (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F16EE3A445A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16EE3A445A FOREIGN KEY (parent_event_id) REFERENCES calendar_events (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F16876C4DDA')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16876C4DDA FOREIGN KEY (organizer_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F16979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F16E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F1655458D')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F1655458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F16ABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F16ABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_events') || $this->constraintExists('calendar_events', 'FK_F9E14F168DB60186')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_events ADD CONSTRAINT FK_F9E14F168DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('calendar_event_attendees') || $this->constraintExists('calendar_event_attendees', 'FK_258586B3A76ED395')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_event_attendees ADD CONSTRAINT FK_258586B3A76ED395 FOREIGN KEY (user_id) REFERENCES calendar_events (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('calendar_event_attendees') || $this->constraintExists('calendar_event_attendees', 'FK_258586B37495C8E3')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE calendar_event_attendees ADD CONSTRAINT FK_258586B37495C8E3 FOREIGN KEY (calendar_event_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('audit_logs') || $this->constraintExists('audit_logs', 'FK_D62F2858A76ED395')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('notification') || $this->constraintExists('notification', 'FK_BF5476CAA76ED395')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('rfq_line_items') || $this->constraintExists('rfq_line_items', 'FK_DD15496DABD9545F')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE rfq_line_items ADD CONSTRAINT FK_DD15496DABD9545F FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('meeting_slots') || $this->constraintExists('meeting_slots', 'FK_A04577D07E3C61F9')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE meeting_slots ADD CONSTRAINT FK_A04577D07E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE;');
        }
        if (!$this->tableExists('meeting_slots') || $this->constraintExists('meeting_slots', 'FK_A04577D0E7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE meeting_slots ADD CONSTRAINT FK_A04577D0E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('meeting_slots') || $this->constraintExists('meeting_slots', 'FK_A04577D0979B1AD6')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE meeting_slots ADD CONSTRAINT FK_A04577D0979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('inbox_messages') || $this->constraintExists('inbox_messages', 'FK_F635C51DDD92DAB8')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE inbox_messages ADD CONSTRAINT FK_F635C51DDD92DAB8 FOREIGN KEY (in_reply_to_id) REFERENCES outbound_messages (id) ON DELETE SET NULL;');
        }
        if (!$this->tableExists('inbox_messages') || $this->constraintExists('inbox_messages', 'FK_F635C51DE7A1254A')) {
            // already present — nothing to do
        } else {
            $this->connection->executeStatement('ALTER TABLE inbox_messages ADD CONSTRAINT FK_F635C51DE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL;');
        }
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty: rolling back a baseline would drop the schema.
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        )->fetchOne();
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $constraint]
        )->fetchOne();
    }
}
