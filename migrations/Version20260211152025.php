<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260211152025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->ifIndexMissing('outbound_messages', 'IDX_642BA0C1ABD9545F', function (): void {
    $this->addSql('ALTER TABLE outbound_messages RENAME INDEX idx_obm_rfq TO IDX_642BA0C1ABD9545F');
});

        $this->ifIndexMissing('rfqs', 'IDX_530068A8E7A1254A', function (): void {
    $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_rfqs_contact TO IDX_530068A8E7A1254A');
});

        $this->ifIndexMissing('rfqs', 'IDX_530068A855458D', function (): void {
    $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_rfqs_lead TO IDX_530068A855458D');
});

        $this->ifColumnMissing('users', 'font_size', function (): void {
    $this->addSql('ALTER TABLE users ADD font_size VARCHAR(20) DEFAULT NULL, ADD density VARCHAR(20) DEFAULT NULL, ADD reduced_motion TINYINT(1) DEFAULT 0 NOT NULL, CHANGE accent_color accent_color VARCHAR(20) DEFAULT NULL');
});

    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE outbound_messages RENAME INDEX idx_642ba0c1abd9545f TO IDX_OBM_RFQ');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_530068a8e7a1254a TO IDX_RFQS_CONTACT');
        $this->addSql('ALTER TABLE rfqs RENAME INDEX idx_530068a855458d TO IDX_RFQS_LEAD');
        $this->addSql('ALTER TABLE users DROP font_size, DROP density, DROP reduced_motion, CHANGE accent_color accent_color VARCHAR(7) DEFAULT NULL');
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
