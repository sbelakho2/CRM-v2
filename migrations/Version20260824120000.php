<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Final schema-sync migration: removes indexes, foreign keys and columns
 * added by the historical chain that the Doctrine entity metadata does not
 * declare (and re-adds one FK the chain dropped), so a fresh install
 * (baseline + chain) ends in exact sync with the mapping.
 *
 * The dropped columns (contacts.subscribed/lead_score,
 * compliance_documents.document_type/sha256_hash/version_id) are legacy
 * additions never referenced by the application code; dropping is guarded by
 * existence checks and never touches other data.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Final entity-sync: drop chain-added indexes/FKs/columns not declared by the mapping';
    }

    public function up(Schema $schema): void
    {
        $this->ifConstraintExists('rfqs', 'FK_BA4CF0F155458D', function (): void {
            $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_BA4CF0F155458D');
        });
        $this->ifConstraintExists('rfqs', 'FK_530068A8E7A1254A', function (): void {
            $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_530068A8E7A1254A');
        });
        $this->ifIndexExists('rfqs', 'IDX_RFQS_LEAD', function (): void {
            $this->addSql('DROP INDEX IDX_RFQS_LEAD ON rfqs');
        });
        $this->ifIndexExists('rfqs', 'idx_rfqs_status', function (): void {
            $this->addSql('DROP INDEX idx_rfqs_status ON rfqs');
        });
        $this->ifIndexExists('rfqs', 'idx_rfqs_type', function (): void {
            $this->addSql('DROP INDEX idx_rfqs_type ON rfqs');
        });
        $this->ifIndexExists('rfqs', 'IDX_RFQS_CONTACT', function (): void {
            $this->addSql('DROP INDEX IDX_RFQS_CONTACT ON rfqs');
        });
        $this->ifIndexExists('rfqs', 'idx_rfqs_created', function (): void {
            $this->addSql('DROP INDEX idx_rfqs_created ON rfqs');
        });
        $this->ifIndexExists('email_sends', 'idx_email_sends_opened', function (): void {
            $this->addSql('DROP INDEX idx_email_sends_opened ON email_sends');
        });
        $this->ifIndexExists('email_sends', 'IDX_633143B3F639F774', function (): void {
            $this->addSql('DROP INDEX IDX_633143B3F639F774 ON email_sends');
        });
        $this->ifIndexExists('email_sends', 'idx_email_sends_clicked', function (): void {
            $this->addSql('DROP INDEX idx_email_sends_clicked ON email_sends');
        });
        $this->ifIndexExists('email_sends', 'IDX_633143B3E7A1254A', function (): void {
            $this->addSql('DROP INDEX IDX_633143B3E7A1254A ON email_sends');
        });
        $this->ifIndexExists('email_sends', 'idx_email_sends_sent_at', function (): void {
            $this->addSql('DROP INDEX idx_email_sends_sent_at ON email_sends');
        });
        $this->ifIndexExists('dataset_version', 'uniq_dataset_uuid', function (): void {
            $this->addSql('DROP INDEX uniq_dataset_uuid ON dataset_version');
        });
        $this->ifIndexExists('contacts', 'idx_contacts_role', function (): void {
            $this->addSql('DROP INDEX idx_contacts_role ON contacts');
        });
        $this->ifIndexExists('contacts', 'idx_contacts_job_title', function (): void {
            $this->addSql('DROP INDEX idx_contacts_job_title ON contacts');
        });
        $this->ifIndexExists('abm_account', 'uniq_abm_domain', function (): void {
            $this->addSql('DROP INDEX uniq_abm_domain ON abm_account');
        });
        $this->ifIndexExists('companies', 'idx_companies_stage', function (): void {
            $this->addSql('DROP INDEX idx_companies_stage ON companies');
        });
        $this->ifIndexExists('companies', 'idx_companies_status', function (): void {
            $this->addSql('DROP INDEX idx_companies_status ON companies');
        });
        $this->ifIndexExists('companies', 'idx_companies_sector', function (): void {
            $this->addSql('DROP INDEX idx_companies_sector ON companies');
        });
        $this->ifIndexExists('companies', 'idx_companies_region', function (): void {
            $this->addSql('DROP INDEX idx_companies_region ON companies');
        });
        $this->ifIndexExists('companies', 'idx_companies_tier', function (): void {
            $this->addSql('DROP INDEX idx_companies_tier ON companies');
        });
        $this->ifIndexExists('companies', 'idx_companies_created', function (): void {
            $this->addSql('DROP INDEX idx_companies_created ON companies');
        });
        $this->ifIndexExists('compliance_documents', 'IDX_compliance_docs_snoozed', function (): void {
            $this->addSql('DROP INDEX IDX_compliance_docs_snoozed ON compliance_documents');
        });
        $this->ifIndexExists('activities', 'idx_activities_type_date', function (): void {
            $this->addSql('DROP INDEX idx_activities_type_date ON activities');
        });
        $this->ifIndexExists('activities', 'idx_activities_date', function (): void {
            $this->addSql('DROP INDEX idx_activities_date ON activities');
        });
        $this->ifIndexExists('activities', 'idx_activities_type', function (): void {
            $this->addSql('DROP INDEX idx_activities_type ON activities');
        });
        $this->ifIndexExists('bom_lines', 'IDX_bom_line_lifecycle', function (): void {
            $this->addSql('DROP INDEX IDX_bom_line_lifecycle ON bom_lines');
        });
        $this->ifConstraintExists('price_history', 'FK_4C9CB817B2EEEC4D', function (): void {
            $this->addSql('ALTER TABLE price_history DROP FOREIGN KEY FK_4C9CB817B2EEEC4D');
        });
        $this->ifConstraintExists('price_history', 'FK_4C9CB817DB805178', function (): void {
            $this->addSql('ALTER TABLE price_history DROP FOREIGN KEY FK_4C9CB817DB805178');
        });
        $this->ifIndexExists('leads', 'IDX_leads_has_contact_form', function (): void {
            $this->addSql('DROP INDEX IDX_leads_has_contact_form ON leads');
        });
        $this->ifConstraintExists('outbound_messages', 'FK_642BA0C1ABD9545F', function (): void {
            $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C1ABD9545F');
        });
        $this->ifIndexExists('outbound_messages', 'IDX_OBM_RFQ', function (): void {
            $this->addSql('DROP INDEX IDX_OBM_RFQ ON outbound_messages');
        });
        $this->ifIndexExists('notification', 'idx_notification_entity', function (): void {
            $this->addSql('DROP INDEX idx_notification_entity ON notification');
        });
        $this->ifColumnExists('contacts', 'subscribed', function (): void {
            $this->addSql('ALTER TABLE contacts DROP subscribed, DROP lead_score');
        });
        $this->ifColumnExists('compliance_documents', 'document_type', function (): void {
            $this->addSql('ALTER TABLE compliance_documents DROP document_type, DROP sha256_hash, DROP version_id');
        });
        $this->ifConstraintMissing('onboarding_packs', 'FK_846C0DCB91D286EE', function (): void {
            $this->addSql('ALTER TABLE onboarding_packs ADD CONSTRAINT FK_846C0DCB91D286EE FOREIGN KEY (portal_candidate_id) REFERENCES portal_candidates (id) ON DELETE SET NULL');
        });
    }

    public function down(Schema $schema): void
    {
        // Intentionally empty: this migration only removes legacy noise.
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

    private function constraintExists(string $table, string $constraint): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = ?',
            [$table, $constraint]
        )->fetchOne();
    }

    private function ifColumnExists(string $table, string $column, callable $fn): void
    {
        if ($this->tableExists($table) && $this->columnExists($table, $column)) {
            $fn();
        }
    }

    private function ifIndexExists(string $table, string $index, callable $fn): void
    {
        if ($this->tableExists($table) && $this->indexExists($table, $index)) {
            $fn();
        }
    }

    private function ifConstraintExists(string $table, string $constraint, callable $fn): void
    {
        if ($this->tableExists($table) && $this->constraintExists($table, $constraint)) {
            $fn();
        }
    }

    private function ifConstraintMissing(string $table, string $constraint, callable $fn): void
    {
        if (!$this->tableExists($table) || $this->constraintExists($table, $constraint)) {
            return;
        }
        $fn();
    }
}
