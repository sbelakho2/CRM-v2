<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251114085644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE IF NOT EXISTS contact_email_campaigns (contact_id INT NOT NULL, email_campaign_id INT NOT NULL, INDEX IDX_776FD465E7A1254A (contact_id), INDEX IDX_776FD465E0F98BC3 (email_campaign_id), PRIMARY KEY(contact_id, email_campaign_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->ifConstraintMissing('contact_email_campaigns', 'FK_776FD465E7A1254A', function (): void {
    $this->addSql('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
});

        $this->ifConstraintMissing('contact_email_campaigns', 'FK_776FD465E0F98BC3', function (): void {
    $this->addSql('ALTER TABLE contact_email_campaigns ADD CONSTRAINT FK_776FD465E0F98BC3 FOREIGN KEY (email_campaign_id) REFERENCES email_campaigns (id) ON DELETE CASCADE');
});

        $this->ifColumnMissing('email_campaigns', 'template_id', function (): void {
    $this->addSql('ALTER TABLE email_campaigns ADD template_id INT DEFAULT NULL');
});

        $this->ifConstraintMissing('email_campaigns', 'FK_EC78EB5B5DA0FB8', function (): void {
    $this->addSql('ALTER TABLE email_campaigns ADD CONSTRAINT FK_EC78EB5B5DA0FB8 FOREIGN KEY (template_id) REFERENCES email_template (id)');
});

        $this->ifIndexMissing('email_campaigns', 'IDX_EC78EB5B5DA0FB8', function (): void {
    $this->addSql('CREATE INDEX IDX_EC78EB5B5DA0FB8 ON email_campaigns (template_id)');
});

        $this->ifIndexExists('users', 'IDX_reset_token', function (): void {
    $this->addSql('DROP INDEX IDX_reset_token ON users');
});

    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact_email_campaigns DROP FOREIGN KEY FK_776FD465E7A1254A');
        $this->addSql('ALTER TABLE contact_email_campaigns DROP FOREIGN KEY FK_776FD465E0F98BC3');
        $this->addSql('DROP TABLE contact_email_campaigns');
        $this->addSql('ALTER TABLE email_campaigns DROP FOREIGN KEY FK_EC78EB5B5DA0FB8');
        $this->addSql('DROP INDEX IDX_EC78EB5B5DA0FB8 ON email_campaigns');
        $this->addSql('ALTER TABLE email_campaigns DROP template_id');
        $this->addSql('CREATE INDEX IDX_reset_token ON users (reset_token)');
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
