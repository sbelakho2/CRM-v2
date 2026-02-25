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
        // Companies table - heavily filtered in CompanyController::index()
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_sector ON companies (sector)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_tier ON companies (account_tier)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_stage ON companies (pipeline_stage)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_status ON companies (company_status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_region ON companies (region)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_companies_created ON companies (created_at)');
        
        // Activities table - used for recent activities, weekly metrics
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_activities_date ON activities (activity_date)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_activities_type ON activities (type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_activities_type_date ON activities (type, activity_date)');
        
        // RFQs table - used in pipeline views
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_rfqs_status ON rfqs (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_rfqs_type ON rfqs (type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_rfqs_created ON rfqs (created_at)');
        
        // Contacts table - searched by role, company
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_contacts_role ON contacts (role)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_contacts_job_title ON contacts (job_title)');
        
        // Email sends - used in campaign analytics
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_email_sends_sent_at ON email_sends (sent_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_email_sends_opened ON email_sends (opened)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_email_sends_clicked ON email_sends (clicked)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes in reverse order
        $this->addSql('DROP INDEX IF EXISTS idx_email_sends_clicked');
        $this->addSql('DROP INDEX IF EXISTS idx_email_sends_opened');
        $this->addSql('DROP INDEX IF EXISTS idx_email_sends_sent_at');
        
        $this->addSql('DROP INDEX IF EXISTS idx_contacts_job_title');
        $this->addSql('DROP INDEX IF EXISTS idx_contacts_role');
        
        $this->addSql('DROP INDEX IF EXISTS idx_rfqs_created');
        $this->addSql('DROP INDEX IF EXISTS idx_rfqs_type');
        $this->addSql('DROP INDEX IF EXISTS idx_rfqs_status');
        
        $this->addSql('DROP INDEX IF EXISTS idx_activities_type_date');
        $this->addSql('DROP INDEX IF EXISTS idx_activities_type');
        $this->addSql('DROP INDEX IF EXISTS idx_activities_date');
        
        $this->addSql('DROP INDEX IF EXISTS idx_companies_created');
        $this->addSql('DROP INDEX IF EXISTS idx_companies_region');
        $this->addSql('DROP INDEX IF EXISTS idx_companies_status');
        $this->addSql('DROP INDEX IF EXISTS idx_companies_stage');
        $this->addSql('DROP INDEX IF EXISTS idx_companies_tier');
        $this->addSql('DROP INDEX IF EXISTS idx_companies_sector');
    }
}
