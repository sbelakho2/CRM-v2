<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales Pipeline Rationalization — add missing FKs and nurturing stage column.
 *
 * Changes:
 *   rfqs              + contact_id  FK → contacts(id) ON DELETE SET NULL
 *   rfqs              + lead_id     FK → leads(id) ON DELETE SET NULL
 *   outbound_messages + rfq_id      FK → rfqs(id) ON DELETE SET NULL
 *   leads             + nurturing_stage VARCHAR(30) NULLABLE + INDEX
 */
final class Version20260211120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales Pipeline Rationalization: add RFQ.contact_id, RFQ.lead_id, OutboundMessage.rfq_id FKs, Lead.nurturing_stage column';
    }

    public function up(Schema $schema): void
    {
        // -- rfqs: add contact_id FK -----------------------------------------
        $this->ifColumnMissing('rfqs', 'contact_id', function (): void {
    $this->addSql('ALTER TABLE rfqs ADD contact_id INT DEFAULT NULL');
});

        $this->ifConstraintMissing('rfqs', 'FK_BA4CF0F1E7A1254A', function (): void {
    $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F1E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
});

        $this->ifIndexMissing('rfqs', 'IDX_RFQS_CONTACT', function (): void {
    $this->addSql('CREATE INDEX IDX_RFQS_CONTACT ON rfqs (contact_id)');
});


        // -- rfqs: add lead_id FK --------------------------------------------
        $this->ifColumnMissing('rfqs', 'lead_id', function (): void {
    $this->addSql('ALTER TABLE rfqs ADD lead_id INT DEFAULT NULL');
});

        $this->ifConstraintMissing('rfqs', 'FK_BA4CF0F155458D', function (): void {
    $this->addSql('ALTER TABLE rfqs ADD CONSTRAINT FK_BA4CF0F155458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL');
});

        $this->ifIndexMissing('rfqs', 'IDX_RFQS_LEAD', function (): void {
    $this->addSql('CREATE INDEX IDX_RFQS_LEAD ON rfqs (lead_id)');
});


        // -- outbound_messages: add rfq_id FK --------------------------------
        $this->ifColumnMissing('outbound_messages', 'rfq_id', function (): void {
    $this->addSql('ALTER TABLE outbound_messages ADD rfq_id INT DEFAULT NULL');
});

        $this->ifConstraintMissing('outbound_messages', 'FK_OBM_RFQ', function (): void {
    $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_OBM_RFQ FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL');
});

        $this->ifIndexMissing('outbound_messages', 'IDX_OBM_RFQ', function (): void {
    $this->addSql('CREATE INDEX IDX_OBM_RFQ ON outbound_messages (rfq_id)');
});


        // -- leads: add nurturing_stage column + index -----------------------
        $this->ifColumnMissing('leads', 'nurturing_stage', function (): void {
    $this->addSql('ALTER TABLE leads ADD nurturing_stage VARCHAR(30) DEFAULT NULL');
});

        $this->ifIndexMissing('leads', 'idx_leads_nurturing', function (): void {
    $this->addSql('CREATE INDEX idx_leads_nurturing ON leads (nurturing_stage)');
});

    }

    public function down(Schema $schema): void
    {
        // -- leads: drop nurturing_stage
        $this->addSql('DROP INDEX idx_leads_nurturing ON leads');
        $this->addSql('ALTER TABLE leads DROP nurturing_stage');

        // -- outbound_messages: drop rfq_id FK
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_OBM_RFQ');
        $this->addSql('DROP INDEX IDX_OBM_RFQ ON outbound_messages');
        $this->addSql('ALTER TABLE outbound_messages DROP rfq_id');

        // -- rfqs: drop lead_id FK
        $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_BA4CF0F155458D');
        $this->addSql('DROP INDEX IDX_RFQS_LEAD ON rfqs');
        $this->addSql('ALTER TABLE rfqs DROP lead_id');

        // -- rfqs: drop contact_id FK
        $this->addSql('ALTER TABLE rfqs DROP FOREIGN KEY FK_BA4CF0F1E7A1254A');
        $this->addSql('DROP INDEX IDX_RFQS_CONTACT ON rfqs');
        $this->addSql('ALTER TABLE rfqs DROP contact_id');
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
