<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-4 implementation-completion migration (forward-only, additive):
 *
 *  1. email_sends.next_attempt_at — exponential-backoff retry scheduling
 *     for FAILED deliveries (the due-send worker retries both QUEUED and
 *     retryable FAILED rows).
 *  2. tariff_rates.duty_type + specific_rate — specific/compound duty
 *     support (the duty engine now computes $/UOM tariffs, not only
 *     ad-valorem).
 *  3. verified_capabilities + verified_certifications — the verified
 *     operating-data registers backing outbound capability/certification
 *     claims (seeded via app:claims:seed-verified).
 *  4. quotes.estimated_cost + margin_override_percent — real margin
 *     tracking (replaces the permanent getMarginPercent() null stub).
 *  5. onboarding_packs.fields_json + pdf_path — the columns the
 *     submission path already reads/writes.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round 4: send backoff column, typed tariffs, verified claims registers, quote margins, pack fields';
    }

    public function up(Schema $schema): void
    {
        $this->addColumnIfMissing('email_sends', 'next_attempt_at', 'DATETIME DEFAULT NULL');

        $this->addColumnIfMissing('tariff_rates', 'duty_type', "VARCHAR(20) DEFAULT 'ad_valorem'");
        $this->addColumnIfMissing('tariff_rates', 'specific_rate', 'NUMERIC(12, 4) DEFAULT NULL');

        $this->addColumnIfMissing('quotes', 'estimated_cost', 'NUMERIC(15, 2) DEFAULT NULL');
        $this->addColumnIfMissing('quotes', 'margin_override_percent', 'NUMERIC(6, 3) DEFAULT NULL');

        $this->addColumnIfMissing('onboarding_packs', 'fields_json', 'LONGTEXT DEFAULT NULL');
        $this->addColumnIfMissing('onboarding_packs', 'pdf_path', 'VARCHAR(500) DEFAULT NULL');

        if (!$this->tableExists('verified_capabilities')) {
            $this->addSql('CREATE TABLE verified_capabilities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site VARCHAR(50) NOT NULL,
                capability_key VARCHAR(100) NOT NULL,
                label VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'verified\',
                verified_at DATETIME DEFAULT NULL,
                valid_until DATETIME DEFAULT NULL,
                evidence_document VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_capability_site_key (site, capability_key)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        }

        if (!$this->tableExists('verified_certifications')) {
            $this->addSql('CREATE TABLE verified_certifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site VARCHAR(50) NOT NULL,
                standard VARCHAR(100) NOT NULL,
                certificate_number VARCHAR(100) DEFAULT NULL,
                issuer VARCHAR(150) DEFAULT NULL,
                valid_from DATE DEFAULT NULL,
                valid_until DATE DEFAULT NULL,
                evidence_document VARCHAR(500) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'verified\',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_certification_site_standard (site, standard)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Additive implementation-completion columns are kept.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->tableExists($table) || $this->columnExists($table, $column)) {
            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
