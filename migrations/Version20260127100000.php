<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Autonomous Sales System V2 Migration
 * 
 * Creates tables for:
 * - bandit_arms: Thompson Sampling multi-armed bandit
 * - spintax_templates: Email templates with spintax syntax
 * - outbound_messages: Sent email tracking with arm IDs for closed-loop
 * - inbox_messages: Incoming email classification
 * - competitor_detections: Competitor targeting (Sniper mode)
 * - learned_competitors: Dynamically learned competitors from scraping
 * - personalization_profiles: ML-style email personalization
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
final class Version20260127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autonomous Sales System V2 - Thompson Sampling, Spintax, Email Classification, Dynamic Competitors, ML Personalization';
    }

    public function up(Schema $schema): void
    {
        // ==================== BANDIT ARMS ====================
        $this->addSql('CREATE TABLE bandit_arms (
            id INT AUTO_INCREMENT NOT NULL,
            arm_type VARCHAR(50) NOT NULL,
            arm_name VARCHAR(255) NOT NULL,
            arm_value LONGTEXT NOT NULL,
            alpha INT DEFAULT 1 NOT NULL,
            beta INT DEFAULT 1 NOT NULL,
            total_trials INT DEFAULT 0 NOT NULL,
            total_successes INT DEFAULT 0 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_bandit_arm_type (arm_type),
            INDEX idx_bandit_active (active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== SPINTAX TEMPLATES ====================
        $this->addSql('CREATE TABLE spintax_templates (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            template_type VARCHAR(50) DEFAULT \'email\' NOT NULL,
            subject_spintax LONGTEXT NOT NULL,
            body_spintax LONGTEXT NOT NULL,
            available_variables JSON NOT NULL,
            times_used INT DEFAULT 0 NOT NULL,
            total_opens INT DEFAULT 0 NOT NULL,
            total_replies INT DEFAULT 0 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_spintax_type (template_type),
            INDEX idx_spintax_active (active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== OUTBOUND MESSAGES ====================
        $this->addSql('CREATE TABLE outbound_messages (
            id INT AUTO_INCREMENT NOT NULL,
            contact_id INT NOT NULL,
            subject_arm_id INT DEFAULT NULL,
            template_id INT DEFAULT NULL,
            subject LONGTEXT NOT NULL,
            body_text LONGTEXT NOT NULL,
            body_html LONGTEXT DEFAULT NULL,
            variation_hash VARCHAR(64) DEFAULT NULL,
            status VARCHAR(20) DEFAULT \'pending\' NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            replied_at DATETIME DEFAULT NULL,
            message_id VARCHAR(255) DEFAULT NULL,
            provider VARCHAR(50) DEFAULT NULL,
            outcome_recorded TINYINT(1) DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            INDEX idx_outbound_contact (contact_id),
            INDEX idx_outbound_arm (subject_arm_id),
            INDEX idx_outbound_status (status),
            INDEX idx_outbound_sent (sent_at),
            CONSTRAINT FK_outbound_contact FOREIGN KEY (contact_id) REFERENCES contacts (id),
            CONSTRAINT FK_outbound_arm FOREIGN KEY (subject_arm_id) REFERENCES bandit_arms (id) ON DELETE SET NULL,
            CONSTRAINT FK_outbound_template FOREIGN KEY (template_id) REFERENCES spintax_templates (id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== INBOX MESSAGES ====================
        $this->addSql('CREATE TABLE inbox_messages (
            id INT AUTO_INCREMENT NOT NULL,
            in_reply_to_id INT DEFAULT NULL,
            contact_id INT DEFAULT NULL,
            from_email VARCHAR(255) NOT NULL,
            subject LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            classification VARCHAR(30) DEFAULT NULL,
            classification_confidence DECIMAL(5,2) DEFAULT NULL,
            classification_method VARCHAR(30) DEFAULT NULL,
            requires_human_review TINYINT(1) DEFAULT 0 NOT NULL,
            human_reviewed_at DATETIME DEFAULT NULL,
            reviewed_by VARCHAR(255) DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            received_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_inbox_classification (classification),
            INDEX idx_inbox_review (requires_human_review),
            INDEX idx_inbox_from (from_email),
            CONSTRAINT FK_inbox_reply FOREIGN KEY (in_reply_to_id) REFERENCES outbound_messages (id) ON DELETE SET NULL,
            CONSTRAINT FK_inbox_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== COMPETITOR DETECTIONS ====================
        $this->addSql('CREATE TABLE competitor_detections (
            id INT AUTO_INCREMENT NOT NULL,
            lead_id INT NOT NULL,
            competitor_domain VARCHAR(255) NOT NULL,
            competitor_name VARCHAR(100) DEFAULT NULL,
            competitor_tier INT DEFAULT 3 NOT NULL,
            detected_in VARCHAR(50) DEFAULT \'website_analysis\' NOT NULL,
            detection_confidence INT DEFAULT 100 NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_competitor_tier (competitor_tier),
            INDEX idx_competitor_domain (competitor_domain),
            UNIQUE INDEX unique_lead_competitor (lead_id, competitor_domain),
            CONSTRAINT FK_competitor_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== LEARNED COMPETITORS (Dynamic) ====================
        $this->addSql('CREATE TABLE learned_competitors (
            id INT AUTO_INCREMENT NOT NULL,
            domain VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            full_name VARCHAR(500) DEFAULT NULL,
            tier SMALLINT DEFAULT 3 NOT NULL,
            industry VARCHAR(50) DEFAULT \'ems\' NOT NULL,
            discovery_source VARCHAR(50) DEFAULT \'website_scrape\' NOT NULL,
            discovery_context LONGTEXT DEFAULT NULL,
            detection_count INT DEFAULT 1 NOT NULL,
            confidence_score INT DEFAULT 50 NOT NULL,
            verified TINYINT(1) DEFAULT 0 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            aliases JSON DEFAULT NULL,
            keywords JSON DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            verified_by VARCHAR(100) DEFAULT NULL,
            UNIQUE INDEX unique_learned_domain (domain),
            INDEX idx_learned_comp_domain (domain),
            INDEX idx_learned_comp_tier (tier),
            INDEX idx_learned_comp_active (active),
            INDEX idx_learned_comp_verified (verified),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== PERSONALIZATION PROFILES (ML) ====================
        $this->addSql('CREATE TABLE personalization_profiles (
            id INT AUTO_INCREMENT NOT NULL,
            contact_id INT DEFAULT NULL,
            company_id INT DEFAULT NULL,
            preferred_tone VARCHAR(50) DEFAULT \'formal\' NOT NULL,
            preferred_content VARCHAR(50) DEFAULT \'business\' NOT NULL,
            preferred_style VARCHAR(50) DEFAULT \'concise\' NOT NULL,
            topic_interests JSON DEFAULT NULL,
            avoid_topics JSON DEFAULT NULL,
            feature_embedding JSON DEFAULT NULL,
            interaction_history JSON DEFAULT NULL,
            emails_opened INT DEFAULT 0 NOT NULL,
            emails_replied INT DEFAULT 0 NOT NULL,
            emails_bounced INT DEFAULT 0 NOT NULL,
            links_clicked INT DEFAULT 0 NOT NULL,
            best_send_time VARCHAR(50) DEFAULT NULL,
            best_send_day VARCHAR(10) DEFAULT NULL,
            successful_subject_patterns JSON DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_pers_contact (contact_id),
            INDEX idx_pers_company (company_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ==================== SEED DEFAULT DATA ====================
        
        // Seed default subject line arms
        $this->addSql("INSERT INTO bandit_arms (arm_type, arm_name, arm_value, alpha, beta, total_trials, total_successes, active, created_at) VALUES
            ('subject_line', 'Direct Question', 'Quick question about {{company_name}} sourcing?', 1, 1, 0, 0, 1, NOW()),
            ('subject_line', 'Value Proposition', 'Morocco manufacturing opportunity for {{company_name}}', 1, 1, 0, 0, 1, NOW()),
            ('subject_line', 'Capability Focus', '{{company_name}}: PCBA capacity in Morocco', 1, 1, 0, 0, 1, NOW()),
            ('subject_line', 'Cost Focus', 'Reducing costs for {{company_name}} with nearshore PCBA', 1, 1, 0, 0, 1, NOW())
        ");

        // Seed default spintax templates - covering all Starz services
        $this->addSql("INSERT INTO spintax_templates (name, description, template_type, subject_spintax, body_spintax, available_variables, times_used, total_opens, total_replies, active, created_at) VALUES
            ('Initial Outreach - General', 'First contact for electronics/electrical manufacturing prospects', 'email', 
             '{Quick question about|Question re:|Regarding} {{company_name}} {sourcing|manufacturing|production}',
             '{Hi|Hello|Hey} {{first_name}},\n\n{I noticed|I came across|I saw} {{company_name}} {during my research|while reviewing companies in your sector|in my market research}.\n\n{We specialize in|Our expertise is in|We focus on} electronics and electrical manufacturing from our {Morocco|Tangier Free Zone} facility - {PCB assembly, cable harnesses, and overmolded assemblies|turnkey electronics manufacturing|complete EMS solutions} for {automotive|industrial|aerospace} OEMs.\n\n{I''d love to|Would be great to|I''m curious to} understand if {{company_name}} {is exploring|considers|looks at} alternative sourcing options.\n\n{Would you be open to|Could we schedule|Any interest in} a brief call to discuss?\n\n{Best regards|Kind regards|Best},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Initial Outreach - Cable Harness', 'First contact for cable/harness assembly prospects', 'email', 
             '{Quick question about|Question re:|Regarding} {{company_name}} {wire harness|cable assembly|wiring} sourcing',
             '{Hi|Hello|Hey} {{first_name}},\n\n{I noticed|I came across|I saw} {{company_name}} {during my research|while reviewing automotive suppliers|in my market research}.\n\n{We manufacture|Our Morocco facility produces|We specialize in} custom cable harnesses and wire assemblies for {automotive|aerospace|industrial} applications - with overmolding, connector integration, and {full traceability|100% testing|export-ready documentation}.\n\n{Are you currently|Is {{company_name}}|Would you be} {evaluating|looking at|considering} alternative harness suppliers for {cost optimization|supply chain diversification|capacity expansion}?\n\n{Happy to share|I can send over|Would be glad to provide} some case studies from similar {automotive|industrial} clients.\n\n{Best regards|Kind regards|Best},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Initial Outreach - PCBA', 'First contact for PCB assembly prospects', 'email', 
             '{Quick question about|Question re:|Regarding} {{company_name}} {PCBA|PCB assembly|electronics} sourcing',
             '{Hi|Hello|Hey} {{first_name}},\n\n{I noticed|I came across|I saw} {{company_name}} {during my research|while reviewing companies in your sector|in my market research}.\n\n{We specialize in|Our expertise is in|We focus on} high-precision PCB assembly from our Tangier facility - {fine-pitch SMT, BGA, multi-layer boards|automated SMT lines with AOI and X-ray|prototype to production PCBA} serving {automotive|industrial|aerospace} OEMs across Europe.\n\n{I''d love to|Would be great to|I''m curious to} understand if {{company_name}} {is exploring|considers|looks at} nearshore PCBA sourcing options.\n\n{Would you be open to|Could we schedule|Any interest in} a brief call to discuss?\n\n{Best regards|Kind regards|Best},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Initial Outreach - Tier1 Auto', 'First contact for Tier 1 automotive suppliers (ideal customers)', 'email', 
             '{Partnership inquiry|Manufacturing capacity|Nearshore EMS}: {{company_name}}',
             '{Dear|Hello} {{first_name}},\n\n{I''m reaching out because|{{company_name}} came up in my research as|I understand} {{company_name}} {sources electronic assemblies and cable harnesses|works with contract manufacturers|partners with EMS providers} for your automotive programs.\n\n{Starz Electronics|We} {operates|has} a {25+ year track record|ISO 9001/UL certified facility|dedicated automotive-grade operation} in {Morocco''s Tangier Free Zone|North Africa}, providing:\n\n• {PCB assembly|PCBA} (fine-pitch, BGA, multi-layer)\n• Cable & wire harness assembly\n• Overmolding & plastic injection\n• {System integration|Turnkey box build}\n\n{Our location offers|The Tangier Free Zone provides|We deliver} {competitive costs with EU proximity|2-3 day shipping to Europe|duty-free exports to EU/US}.\n\n{Would it make sense to|Could we|I''d welcome the opportunity to} discuss how we might support {{company_name}}''s {manufacturing needs|sourcing strategy|supply chain}?\n\n{Best regards|Kind regards|Respectfully},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Follow-up #1 - Value Add', 'First follow-up with additional value', 'email',
             '{Re: |Following up: |Quick follow-up on }{{company_name}}',
             '{Hi|Hey} {{first_name}},\n\n{Just wanted to follow up|Circling back|Bumping this up} on my previous message.\n\n{A quick stat|One thing worth noting|What might interest you}: {our Morocco facility|we} {achieved|delivered} {98.5% first-pass yield|<50ppm defect rates|zero recalls} for {automotive|aerospace} clients last quarter.\n\n{We handle everything from|Our capabilities span|We offer} PCB assembly through cable harnesses to {complete system integration|turnkey box build|overmolded assemblies}.\n\n{Would this level of quality|Does this kind of performance} be relevant for {{company_name}}''s {requirements|needs|standards}?\n\n{Let me know|Happy to chat|Open to a call} when convenient.\n\n{Cheers|Best|Regards},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Follow-up #2 - Final', 'Final follow-up before closing sequence', 'email',
             '{Last attempt|Final check-in|Closing the loop}: {{company_name}}',
             '{{first_name}},\n\n{I''ll keep this short|Quick one|Last message from me on this}.\n\n{If nearshore manufacturing isn''t a priority right now, totally understand.|I realize timing might not be right.|No worries if this isn''t on your radar at the moment.}\n\n{Just reply|Let me know|Drop me a line} {\"not now\"|\"later this year\"|\"interested\"} and I''ll {follow up accordingly|adjust my timing|note for future}.\n\n{Thanks for your time|Appreciate your consideration|Thanks either way},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW()),
            
            ('Competitor Displacement', 'Outreach when prospect uses a known competitor', 'email',
             '{Alternative to|Comparing|Second source for} your {EMS|contract manufacturing} needs',
             '{Hi|Hello} {{first_name}},\n\n{I understand|I noticed|Our research indicates} {{company_name}} {works with|currently sources from|partners with} EMS providers in {the region|North Africa/Eastern Europe|your supply chain}.\n\n{Many companies like yours|Several automotive OEMs|A number of our clients} have {added us as a second source|diversified their supply chain with us|found value in our Morocco operation} for:\n\n• {Risk mitigation|Supply chain resilience|Dual sourcing}\n• {Competitive pricing|Cost optimization|Better economics}\n• {Faster EU delivery|Shorter lead times|Nearshore advantages}\n\n{Would a quick comparison|Could a capability review|Would it help to see how we} {make sense|be valuable|stack up}?\n\n{Best|Regards|Best regards},\n{{sender_name}}',
             '[\"first_name\", \"company_name\", \"sender_name\"]', 0, 0, 0, 1, NOW())
        ");

        // Seed learned competitors - North Africa and Eastern Europe contract manufacturers
        // Starz services: PCBA, Cable Harness, Overmolding, Windings, System Integration
        // Industries: Automotive, Aerospace, Industrial
        // NOTE: Tier 1 auto suppliers (Aptiv, Yazaki, Leoni, Valeo, Lear) are ideal CUSTOMERS!
        $this->addSql("INSERT INTO learned_competitors (domain, name, full_name, tier, industry, discovery_source, detection_count, confidence_score, verified, active, first_detected_at, last_detected_at) VALUES
            -- Tier 1: Direct competitors (North Africa - EMS & Cable Harness)
            ('telnet-group.com', 'Telnet', 'Telnet Holding', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('all-circuits.com', 'All Circuits', 'All Circuits Group', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('actia.com', 'Actia', 'Actia Group', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('eolane.com', 'Eolane', 'Eolane', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('premo-group.com', 'Premo', 'Premo Group', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('coficab.com', 'Coficab', 'Coficab Group', 1, 'cable_harness', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('kromberg-schubert.com', 'Kromberg', 'Kromberg & Schubert', 1, 'cable_harness', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('draexlmaier.com', 'Draexlmaier', 'Draexlmaier Group', 1, 'cable_harness', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('matis-aerospace.com', 'Matis', 'Matis Aerospace', 1, 'ems', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            ('sews-cabind.com', 'SEWS-Cabind', 'SEWS-Cabind', 1, 'cable_harness', 'manual', 100, 100, 1, 1, NOW(), NOW()),
            -- Tier 2: Eastern European EMS competitors
            ('fideltronik.com', 'Fideltronik', 'Fideltronik', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('videoton.hu', 'Videoton', 'Videoton Holding', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('katek.de', 'KATEK', 'KATEK SE', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('kitron.com', 'Kitron', 'Kitron ASA', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('scanfil.com', 'Scanfil', 'Scanfil EMS', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('enics.com', 'Enics', 'Enics AG', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('gpvintl.com', 'GPV', 'GPV International', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('zollner.de', 'Zollner', 'Zollner Elektronik', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('cicor.com', 'Cicor', 'Cicor Group', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            ('incap.com', 'Incap', 'Incap Corporation', 2, 'ems', 'manual', 50, 100, 1, 1, NOW(), NOW()),
            -- Tier 3: Global EMS with regional presence
            ('flex.com', 'Flex', 'Flex Ltd', 3, 'ems', 'manual', 25, 100, 1, 1, NOW(), NOW()),
            ('jabil.com', 'Jabil', 'Jabil Inc', 3, 'ems', 'manual', 25, 100, 1, 1, NOW(), NOW()),
            ('celestica.com', 'Celestica', 'Celestica Inc', 3, 'ems', 'manual', 25, 100, 1, 1, NOW(), NOW()),
            ('sanmina.com', 'Sanmina', 'Sanmina Corporation', 3, 'ems', 'manual', 25, 100, 1, 1, NOW(), NOW()),
            ('plexus.com', 'Plexus', 'Plexus Corp', 3, 'ems', 'manual', 25, 100, 1, 1, NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS personalization_profiles');
        $this->addSql('DROP TABLE IF EXISTS learned_competitors');
        $this->addSql('DROP TABLE IF EXISTS competitor_detections');
        $this->addSql('DROP TABLE IF EXISTS inbox_messages');
        $this->addSql('DROP TABLE IF EXISTS outbound_messages');
        $this->addSql('DROP TABLE IF EXISTS spintax_templates');
        $this->addSql('DROP TABLE IF EXISTS bandit_arms');
    }
}
