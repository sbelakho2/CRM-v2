<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Autonomous Sales System V2.1 - Fixes and Enhancements
 * 
 * Adds:
 * - sa_bayes_training table for persistent Naive Bayes model
 * - personalization_archetypes table for cold-start ML bootstrapping
 * - bandit_arm_last_used tracking for confidence decay
 * - outbound_messages.recorded_event_type for proper Thompson tracking
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
final class Version20260126180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autonomous Sales V2.1 - Bayes persistence, archetype profiles, Thompson tracking fixes';
    }

    public function up(Schema $schema): void
    {
        // ==================== BAYES TRAINING TABLE ====================
        // Stores word frequencies per classification for learning Naive Bayes model
        $this->addSql('CREATE TABLE IF NOT EXISTS sa_bayes_training ( id INT AUTO_INCREMENT NOT NULL, word VARCHAR(100) NOT NULL, classification VARCHAR(30) NOT NULL, frequency INT DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL, UNIQUE INDEX unique_word_class (word, classification), INDEX idx_bayes_classification (classification), INDEX idx_bayes_word (word), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');


        // ==================== PERSONALIZATION ARCHETYPES ====================
        // Synthetic profiles for cold-start collaborative filtering
        $this->addSql('CREATE TABLE IF NOT EXISTS personalization_archetypes ( id INT AUTO_INCREMENT NOT NULL, archetype_name VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, target_industry VARCHAR(50) NOT NULL, target_role VARCHAR(50) NOT NULL, preferred_tone VARCHAR(50) DEFAULT \'formal\' NOT NULL, preferred_content VARCHAR(50) DEFAULT \'business\' NOT NULL, preferred_style VARCHAR(50) DEFAULT \'concise\' NOT NULL, feature_embedding JSON NOT NULL, synthetic_engagement_score INT DEFAULT 75 NOT NULL, topic_interests JSON DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX unique_archetype_name (archetype_name), INDEX idx_archetype_industry (target_industry), INDEX idx_archetype_role (target_role), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');


        // ==================== ADD COLUMNS TO BANDIT_ARMS ====================
        // Add last_used_at for confidence decay calculations
        $this->ifColumnMissing('bandit_arms', 'last_used_at', function (): void {
    $this->addSql('ALTER TABLE bandit_arms ADD COLUMN last_used_at DATETIME DEFAULT NULL AFTER updated_at');
});

        
        // Update existing arms with current timestamp
        $this->addSql('UPDATE bandit_arms SET last_used_at = NOW() WHERE last_used_at IS NULL');


        // ==================== UPDATE OUTBOUND_MESSAGES ====================
        // Change outcome tracking from boolean to event type for proper separation
        $this->ifColumnMissing('outbound_messages', 'recorded_event_type', function (): void {
    $this->addSql('ALTER TABLE outbound_messages ADD COLUMN recorded_event_type VARCHAR(20) DEFAULT NULL AFTER outcome_recorded, ADD COLUMN reply_classification VARCHAR(30) DEFAULT NULL AFTER recorded_event_type, ADD COLUMN reply_content TEXT DEFAULT NULL AFTER reply_classification');
});


        // ==================== SEED INITIAL BAYES TRAINING DATA ====================
        // Seed with base training data (will be updated from human reviews)
        $this->ifTableEmpty('sa_bayes_training', function (): void {
    $this->addSql('INSERT INTO sa_bayes_training (word, classification, frequency, last_updated) VALUES (\'interested\', \'interested\', 15, NOW()), (\'call\', \'interested\', 12, NOW()), (\'discuss\', \'interested\', 10, NOW()), (\'meeting\', \'interested\', 10, NOW()), (\'schedule\', \'interested\', 9, NOW()), (\'learn\', \'interested\', 8, NOW()), (\'more\', \'interested\', 8, NOW()), (\'talk\', \'interested\', 8, NOW()), (\'great\', \'interested\', 7, NOW()), (\'perfect\', \'interested\', 7, NOW()), (\'sounds\', \'interested\', 7, NOW()), (\'timing\', \'interested\', 6, NOW()), (\'yes\', \'interested\', 8, NOW()), (\'definitely\', \'interested\', 6, NOW()), (\'connect\', \'interested\', 6, NOW()), (\'forward\', \'interested\', 5, NOW()), (\'opportunity\', \'interested\', 5, NOW()), (\'exploring\', \'interested\', 4, NOW()), (\'curious\', \'interested\', 4, NOW()), (\'relevant\', \'interested\', 4, NOW()), (\'not\', \'not_interested\', 15, NOW()), (\'no\', \'not_interested\', 14, NOW()), (\'thanks\', \'not_interested\', 10, NOW()), (\'thank\', \'not_interested\', 10, NOW()), (\'remove\', \'not_interested\', 9, NOW()), (\'unsubscribe\', \'not_interested\', 9, NOW()), (\'stop\', \'not_interested\', 8, NOW()), (\'don\\\'t\', \'not_interested\', 7, NOW()), (\'wrong\', \'not_interested\', 6, NOW()), (\'already\', \'not_interested\', 6, NOW()), (\'have\', \'not_interested\', 5, NOW()), (\'pass\', \'not_interested\', 6, NOW()), (\'decline\', \'not_interested\', 6, NOW()), (\'need\', \'not_interested\', 4, NOW()), (\'fit\', \'not_interested\', 5, NOW()), (\'budget\', \'not_interested\', 4, NOW()), (\'vendor\', \'not_interested\', 4, NOW()), (\'contract\', \'not_interested\', 3, NOW()), (\'out\', \'out_of_office\', 12, NOW()), (\'office\', \'out_of_office\', 12, NOW()), (\'vacation\', \'out_of_office\', 10, NOW()), (\'away\', \'out_of_office\', 9, NOW()), (\'return\', \'out_of_office\', 8, NOW()), (\'back\', \'out_of_office\', 7, NOW()), (\'limited\', \'out_of_office\', 6, NOW()), (\'access\', \'out_of_office\', 5, NOW()), (\'automatic\', \'out_of_office\', 8, NOW()), (\'reply\', \'out_of_office\', 7, NOW()), (\'delivery\', \'bounce\', 12, NOW()), (\'failed\', \'bounce\', 12, NOW()), (\'undeliverable\', \'bounce\', 10, NOW()), (\'mailbox\', \'bounce\', 8, NOW()), (\'full\', \'bounce\', 6, NOW()), (\'unknown\', \'bounce\', 8, NOW()), (\'rejected\', \'bounce\', 7, NOW()), (\'exist\', \'bounce\', 6, NOW()), (\'permanent\', \'bounce\', 7, NOW()), (\'error\', \'bounce\', 5, NOW())');
});


        // ==================== SEED ARCHETYPE PROFILES ====================
        // These provide cold-start similarity targets until real data accumulates
        $this->ifTableEmpty('personalization_archetypes', function (): void {
    $this->addSql('INSERT INTO personalization_archetypes (archetype_name, description, target_industry, target_role, preferred_tone, preferred_content, preferred_style, feature_embedding, synthetic_engagement_score, topic_interests, created_at) VALUES (\'Automotive Procurement Manager\', \'Tier 1/2 auto supplier procurement lead focused on cost and quality\', \'automotive\', \'procurement\', \'formal\', \'business\', \'concise\', \'[0.9,0.8,0.7,0.6,0.1,0.2,0.3,0.4,0.9,0.2,0.3,0.8,0.7,0.4,0.5,0.6,0.8,0.72,0.64,0.56,0.48,0.4,0.32,0.24,0.9,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.7,0.5,0.4,0.8,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,1.0,0.0,0.0,0.0,1.0,1.0,0.0,1.0,1.0,1.0,0.0,0.0,0.0,0.0,0.0,0.0]\', 82, \'["cost_optimization","quality_certification","supply_chain"]\', NOW()), (\'Aerospace Engineering Director\', \'Aerospace R&D/engineering leader focused on technical capabilities and certifications\', \'aerospace\', \'engineering\', \'formal\', \'technical\', \'detailed\', \'[0.8,0.9,0.6,0.5,0.2,0.3,0.4,0.5,0.3,0.9,0.8,0.4,0.5,0.7,0.6,0.5,0.9,0.81,0.72,0.63,0.54,0.45,0.36,0.27,0.9,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.6,0.7,0.5,0.5,0.6,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,1.0,0.0,0.0,0.0,0.0,1.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0,1.0,1.0]\', 78, \'["certifications","technical_capabilities","prototyping"]\', NOW()), (\'Industrial Quality Manager\', \'Industrial equipment quality/compliance lead focused on standards and traceability\', \'industrial\', \'quality\', \'formal\', \'technical\', \'detailed\', \'[0.7,0.6,0.9,0.5,0.3,0.4,0.5,0.3,0.4,0.8,0.7,0.5,0.9,0.6,0.5,0.4,0.7,0.63,0.56,0.49,0.42,0.35,0.28,0.21,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.6,0.4,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,0.0,0.0,1.0,1.0,0.0,1.0,0.0,0.0,0.0,1.0,0.0,1.0,1.0,1.0,1.0]\', 75, \'["iso_certifications","traceability","testing"]\', NOW()), (\'Medical Device Supply Chain\', \'Medical device supply chain/ops lead focused on compliance and reliability\', \'medical\', \'supply_chain\', \'formal\', \'value_focused\', \'concise\', \'[0.5,0.4,0.3,0.2,0.9,0.8,0.7,0.6,0.8,0.3,0.6,0.7,0.5,0.9,0.4,0.5,0.85,0.765,0.68,0.595,0.51,0.425,0.34,0.255,0.5,0.5,0.9,0.5,0.5,0.5,0.5,0.5,0.6,0.5,0.3,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,0.0,1.0,0.0,0.0,1.0,0.0,1.0,0.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 80, \'["compliance","iso13485","reliability"]\', NOW()), (\'Telecom Operations Manager\', \'Telecom/infrastructure ops leader focused on capacity and lead times\', \'telecom\', \'operations\', \'direct\', \'business\', \'bullet_points\', \'[0.3,0.2,0.4,0.3,0.7,0.6,0.9,0.8,0.6,0.5,0.9,0.6,0.4,0.8,0.7,0.3,0.7,0.63,0.56,0.49,0.42,0.35,0.28,0.21,0.5,0.5,0.5,0.9,0.8,0.5,0.5,0.5,0.7,0.4,0.5,0.6,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 72, \'["capacity","lead_times","scalability"]\', NOW()), (\'Automotive Harness Engineer\', \'Wire harness/cable assembly engineer at auto supplier\', \'automotive\', \'engineering\', \'friendly\', \'technical\', \'detailed\', \'[0.9,0.8,0.7,0.6,0.1,0.2,0.3,0.4,0.3,0.9,0.8,0.4,0.5,0.7,0.6,0.5,0.75,0.675,0.6,0.525,0.45,0.375,0.3,0.225,0.9,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.7,0.4,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,1.0,0.0,0.0,0.0,0.0,1.0,1.0,0.0,0.0,0.0,1.0,1.0,0.0,0.0,0.0,0.0]\', 76, \'["wire_harness","overmolding","connector_integration"]\', NOW()), (\'Defense Program Manager\', \'Defense contractor program manager focused on ITAR compliance and traceability\', \'defense\', \'management\', \'formal\', \'relationship\', \'detailed\', \'[0.6,0.7,0.5,0.9,0.4,0.5,0.6,0.2,0.7,0.4,0.5,0.9,0.8,0.3,0.4,0.7,0.9,0.81,0.72,0.63,0.54,0.45,0.36,0.27,0.5,0.5,0.5,0.5,0.9,0.7,0.5,0.5,0.6,0.5,0.4,0.6,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,1.0,0.0,1.0,0.0,0.0,1.0,0.0,0.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 74, \'["compliance","traceability","program_support"]\', NOW()), (\'Consumer Electronics Buyer\', \'Consumer electronics procurement focused on cost and volume\', \'consumer\', \'procurement\', \'casual\', \'value_focused\', \'concise\', \'[0.4,0.3,0.2,0.1,0.8,0.9,0.8,0.7,0.9,0.2,0.3,0.8,0.7,0.4,0.5,0.6,0.6,0.54,0.48,0.42,0.36,0.3,0.24,0.18,0.5,0.5,0.5,0.5,0.5,0.5,0.9,0.8,0.8,0.4,0.5,0.9,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,0.0,0.0,0.0,0.0,1.0,0.0,1.0,0.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 70, \'["volume_pricing","quick_turnaround","cost"]\', NOW()), (\'Renewables Sourcing Manager\', \'Renewable energy sourcing lead focused on reliability and sustainability\', \'industrial\', \'supply_chain\', \'friendly\', \'value_focused\', \'concise\', \'[0.7,0.6,0.9,0.5,0.3,0.4,0.5,0.3,0.8,0.3,0.6,0.7,0.5,0.9,0.4,0.5,0.7,0.63,0.56,0.49,0.42,0.35,0.28,0.21,0.9,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.3,0.6,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.0,0.0,0.0,1.0,0.0,1.0,0.0,1.0,0.0,0.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 73, \'["sustainability","reliability","partnerships"]\', NOW()), (\'EV Startup CTO\', \'Electric vehicle startup technical founder focused on speed and innovation\', \'automotive\', \'management\', \'casual\', \'technical\', \'bullet_points\', \'[0.9,0.8,0.7,0.6,0.1,0.2,0.3,0.4,0.7,0.4,0.5,0.9,0.8,0.3,0.4,0.7,0.5,0.45,0.4,0.35,0.3,0.25,0.2,0.15,0.9,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.8,0.6,0.5,0.7,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,0.5,1.0,0.0,0.0,0.0,0.0,0.0,1.0,0.0,1.0,1.0,0.0,0.0,1.0,1.0,1.0,1.0]\', 85, \'["rapid_prototyping","npi","innovation"]\', NOW())');
});

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sa_bayes_training');
        $this->addSql('DROP TABLE IF EXISTS personalization_archetypes');
        $this->addSql('ALTER TABLE bandit_arms DROP COLUMN last_used_at');
        $this->addSql('ALTER TABLE outbound_messages DROP COLUMN recorded_event_type, DROP COLUMN reply_classification, DROP COLUMN reply_content');
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
