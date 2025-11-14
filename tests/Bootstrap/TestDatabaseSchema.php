<?php

namespace App\Tests\Bootstrap;

use Doctrine\DBAL\Connection;

class TestDatabaseSchema
{
    public static function createSchema(Connection $connection): void
    {
        $schema = [
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email VARCHAR(180) NOT NULL,
                roles CLOB NOT NULL,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NULL,
                role VARCHAR(50) NULL,
                territory VARCHAR(100) NULL,
                active BOOLEAN NOT NULL DEFAULT 1
            )',
            'CREATE TABLE IF NOT EXISTS email_campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT "EN",
                description TEXT NULL,
                touch_count INTEGER NOT NULL DEFAULT 5,
                touch_templates CLOB NOT NULL DEFAULT "[]",
                ab_test_variants CLOB NULL,
                active BOOLEAN NOT NULL DEFAULT 0,
                scheduled_at DATETIME NULL,
                template_id INTEGER NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS email_sends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                contact_id INTEGER NOT NULL,
                touch_number INTEGER NOT NULL,
                sent_at DATETIME NULL,
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS contacts_email_campaigns (
                contact_id INTEGER NOT NULL,
                campaign_id INTEGER NOT NULL,
                PRIMARY KEY (contact_id, campaign_id),
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE
            )'
        ];

        // Drop all tables first to ensure clean state
        $connection->executeStatement("DROP TABLE IF EXISTS contacts_email_campaigns");
        $connection->executeStatement("DROP TABLE IF EXISTS email_sends");
        $connection->executeStatement("DROP TABLE IF EXISTS email_campaigns");
        $connection->executeStatement("DROP TABLE IF EXISTS users");

        // Create tables
        foreach ($schema as $sql) {
            $connection->executeStatement($sql);
        }
    }
}