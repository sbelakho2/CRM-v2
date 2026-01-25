<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create price_history table for historical price tracking from distributor APIs
 */
final class Version20260125160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create price_history table for historical price tracking and trend analysis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_history (
            id INT AUTO_INCREMENT NOT NULL,
            quote_id INT DEFAULT NULL,
            bom_line_id INT DEFAULT NULL,
            mpn VARCHAR(255) NOT NULL,
            matched_mpn VARCHAR(255) DEFAULT NULL,
            manufacturer VARCHAR(255) DEFAULT NULL,
            source VARCHAR(50) NOT NULL,
            unit_price DECIMAL(10, 4) NOT NULL,
            price_breaks JSON NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT \'USD\',
            unit_price_usd DECIMAL(10, 4) DEFAULT NULL,
            stock_available INT DEFAULT NULL,
            lead_time_days INT DEFAULT NULL,
            lifecycle_status VARCHAR(50) DEFAULT NULL,
            confidence_score SMALLINT DEFAULT NULL,
            confidence_level VARCHAR(20) DEFAULT NULL,
            moq INT DEFAULT NULL,
            pack_quantity INT DEFAULT NULL,
            recorded_at DATETIME NOT NULL,
            INDEX idx_ph_mpn (mpn),
            INDEX idx_ph_source (source),
            INDEX idx_ph_date (recorded_at),
            INDEX idx_ph_mpn_source (mpn, source),
            INDEX IDX_QUOTE (quote_id),
            INDEX IDX_BOMLINE (bom_line_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        $this->addSql('ALTER TABLE price_history ADD CONSTRAINT FK_PH_QUOTE FOREIGN KEY (quote_id) REFERENCES quotes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE price_history ADD CONSTRAINT FK_PH_BOMLINE FOREIGN KEY (bom_line_id) REFERENCES bom_lines (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_history DROP FOREIGN KEY FK_PH_QUOTE');
        $this->addSql('ALTER TABLE price_history DROP FOREIGN KEY FK_PH_BOMLINE');
        $this->addSql('DROP TABLE price_history');
    }
}
