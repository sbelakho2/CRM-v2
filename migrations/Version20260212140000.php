<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize legacy hex accent colors to named values.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE users SET accent_color = 'orange' WHERE LOWER(accent_color) IN ('#ffbe00','ffbe00')");
        $this->addSql("UPDATE users SET accent_color = 'blue' WHERE LOWER(accent_color) IN ('#3b82f6','3b82f6')");
        $this->addSql("UPDATE users SET accent_color = 'green' WHERE LOWER(accent_color) IN ('#22c55e','22c55e')");
        $this->addSql("UPDATE users SET accent_color = 'purple' WHERE LOWER(accent_color) IN ('#8b5cf6','8b5cf6')");
        $this->addSql("UPDATE users SET accent_color = 'red' WHERE LOWER(accent_color) IN ('#ef4444','ef4444')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE users SET accent_color = '#FFBE00' WHERE accent_color = 'orange'");
        $this->addSql("UPDATE users SET accent_color = '#3B82F6' WHERE accent_color = 'blue'");
        $this->addSql("UPDATE users SET accent_color = '#22C55E' WHERE accent_color = 'green'");
        $this->addSql("UPDATE users SET accent_color = '#8B5CF6' WHERE accent_color = 'purple'");
        $this->addSql("UPDATE users SET accent_color = '#EF4444' WHERE accent_color = 'red'");
    }
}