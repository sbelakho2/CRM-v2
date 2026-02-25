<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization migration - adds indexes for frequently queried columns.
 * These indexes drastically improve query performance for:
 * - Company listing/filtering
 * - Activity retrieval 
 * - RFQ pipeline views
 * - Contact searches
 */
final class Version20260225100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for companies, activities, rfqs, and contacts tables';
    }

    public function up(Schema $schema): void
    {
        // Use MySQL-compatible syntax - check and create indexes
        $this->createIndexIfNotExists('companies', 'idx_companies_sector', 'sector');
        $this->createIndexIfNotExists('companies', 'idx_companies_tier', 'account_tier');
        $this->createIndexIfNotExists('companies', 'idx_companies_stage', 'pipeline_stage');
        $this->createIndexIfNotExists('companies', 'idx_companies_status', 'company_status');
        $this->createIndexIfNotExists('companies', 'idx_companies_region', 'region');
        $this->createIndexIfNotExists('companies', 'idx_companies_created', 'created_at');
        
        $this->createIndexIfNotExists('activities', 'idx_activities_date', 'activity_date');
        $this->createIndexIfNotExists('activities', 'idx_activities_type', 'type');
        $this->createCompositeIndexIfNotExists('activities', 'idx_activities_type_date', ['type', 'activity_date']);
        
        $this->createIndexIfNotExists('rfqs', 'idx_rfqs_status', 'status');
        $this->createIndexIfNotExists('rfqs', 'idx_rfqs_type', 'type');
        $this->createIndexIfNotExists('rfqs', 'idx_rfqs_created', 'created_at');
        
        $this->createIndexIfNotExists('contacts', 'idx_contacts_role', 'role');
        $this->createIndexIfNotExists('contacts', 'idx_contacts_job_title', 'job_title');
        
        $this->createIndexIfNotExists('email_sends', 'idx_email_sends_sent_at', 'sent_at');
        $this->createIndexIfNotExists('email_sends', 'idx_email_sends_opened', 'opened');
        $this->createIndexIfNotExists('email_sends', 'idx_email_sends_clicked', 'clicked');
    }

    public function down(Schema $schema): void
    {
        // MySQL supports DROP INDEX ... ON table
        $this->dropIndexIfExists('email_sends', 'idx_email_sends_clicked');
        $this->dropIndexIfExists('email_sends', 'idx_email_sends_opened');
        $this->dropIndexIfExists('email_sends', 'idx_email_sends_sent_at');
        
        $this->dropIndexIfExists('contacts', 'idx_contacts_job_title');
        $this->dropIndexIfExists('contacts', 'idx_contacts_role');
        
        $this->dropIndexIfExists('rfqs', 'idx_rfqs_created');
        $this->dropIndexIfExists('rfqs', 'idx_rfqs_type');
        $this->dropIndexIfExists('rfqs', 'idx_rfqs_status');
        
        $this->dropIndexIfExists('activities', 'idx_activities_type_date');
        $this->dropIndexIfExists('activities', 'idx_activities_type');
        $this->dropIndexIfExists('activities', 'idx_activities_date');
        
        $this->dropIndexIfExists('companies', 'idx_companies_created');
        $this->dropIndexIfExists('companies', 'idx_companies_region');
        $this->dropIndexIfExists('companies', 'idx_companies_status');
        $this->dropIndexIfExists('companies', 'idx_companies_stage');
        $this->dropIndexIfExists('companies', 'idx_companies_tier');
        $this->dropIndexIfExists('companies', 'idx_companies_sector');
    }

    private function createIndexIfNotExists(string $table, string $indexName, string $column): void
    {
        $conn = $this->connection;
        $exists = $conn->executeQuery(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        )->fetchOne();
        
        if (!$exists) {
            $this->addSql("CREATE INDEX {$indexName} ON {$table} ({$column})");
        }
    }

    private function createCompositeIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        $conn = $this->connection;
        $exists = $conn->executeQuery(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        )->fetchOne();
        
        if (!$exists) {
            $cols = implode(', ', $columns);
            $this->addSql("CREATE INDEX {$indexName} ON {$table} ({$cols})");
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $conn = $this->connection;
        $exists = $conn->executeQuery(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?",
            [$table, $indexName]
        )->fetchOne();
        
        if ($exists) {
            $this->addSql("DROP INDEX {$indexName} ON {$table}");
        }
    }
}
