<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add snooze functionality to compliance_documents table
 * 
 * This migration adds three columns to support temporary alert suppression:
 * - snoozed_until: DATE - alerts are suppressed until this date
 * - snooze_reason: VARCHAR(255) - optional explanation for snoozing
 * - snoozed_by: VARCHAR(100) - user who initiated the snooze
 */
final class Version20260126190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add snooze functionality to compliance_documents (snoozed_until, snooze_reason, snoozed_by)';
    }

    public function up(Schema $schema): void
    {
        // Add snooze columns to compliance_documents
        $this->addSql('ALTER TABLE compliance_documents ADD snoozed_until DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD snooze_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE compliance_documents ADD snoozed_by VARCHAR(100) DEFAULT NULL');
        
        // Add index for efficient queries on snoozed documents
        $this->addSql('CREATE INDEX IDX_compliance_docs_snoozed ON compliance_documents (snoozed_until)');
    }

    public function down(Schema $schema): void
    {
        // Remove index
        $this->addSql('DROP INDEX IDX_compliance_docs_snoozed ON compliance_documents');
        
        // Remove columns
        $this->addSql('ALTER TABLE compliance_documents DROP snoozed_until');
        $this->addSql('ALTER TABLE compliance_documents DROP snooze_reason');
        $this->addSql('ALTER TABLE compliance_documents DROP snoozed_by');
    }
}
