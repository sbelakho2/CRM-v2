<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen fx_rates.rate from DECIMAL(12,6) to DECIMAL(18,6).
 *
 * Real-world exchange rates exceed the old column's 999,999.999999 ceiling:
 * the Iranian rial (USD/IRR ≈ 1,465,128) overflowed it and made
 * app:fx-rates:fetch crash with SQLSTATE 22003. DECIMAL(18,6) covers every
 * real currency pair with ample headroom. The MODIFY is idempotent and
 * preserves all existing rows.
 */
final class Version20260824130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen fx_rates.rate to DECIMAL(18,6) to fit high-value currency pairs (e.g. USD/IRR)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fx_rates MODIFY rate DECIMAL(18, 6) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fx_rates MODIFY rate DECIMAL(12, 6) NOT NULL');
    }
}
