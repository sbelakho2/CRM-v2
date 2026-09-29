<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Round-7 P0: durable customer quote requests + acceptance records.
 * Both tables anchor customer-facing contracts: a request must exist before
 * "submitted" is returned, and an acceptance stores WHO accepted WHAT at
 * WHICH price (immutable snapshot) alongside the quote state transition.
 */
final class Version20260930000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Round 7: quote_customer_requests + quote_acceptances tables';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('quote_customer_requests')) {
            $this->addSql('CREATE TABLE quote_customer_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                request_type VARCHAR(40) NOT NULL,
                requested_quantity INT DEFAULT NULL,
                estimated_total NUMERIC(15, 2) DEFAULT NULL,
                currency VARCHAR(10) DEFAULT NULL,
                customer_notes LONGTEXT DEFAULT NULL,
                token_fingerprint VARCHAR(64) DEFAULT NULL,
                request_ip VARCHAR(45) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL,
                handled_at DATETIME DEFAULT NULL,
                INDEX idx_qcr_quote (quote_id),
                INDEX idx_qcr_status (status),
                CONSTRAINT FK_qcr_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        }

        if (!$this->tableExists('quote_acceptances')) {
            $this->addSql('CREATE TABLE quote_acceptances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                quote_id INT NOT NULL,
                accepted_quantity INT NOT NULL,
                accepted_total NUMERIC(15, 2) NOT NULL,
                currency VARCHAR(10) DEFAULT NULL,
                customer_name VARCHAR(255) DEFAULT NULL,
                customer_email VARCHAR(255) DEFAULT NULL,
                customer_phone VARCHAR(50) DEFAULT NULL,
                customer_company VARCHAR(255) DEFAULT NULL,
                po_number VARCHAR(100) DEFAULT NULL,
                token_fingerprint VARCHAR(64) DEFAULT NULL,
                pricing_snapshot JSON DEFAULT NULL,
                accepted_at DATETIME NOT NULL,
                INDEX idx_qa_quote (quote_id),
                CONSTRAINT FK_qa_quote FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE RESTRICT
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Customer request/acceptance records are business history — kept.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
