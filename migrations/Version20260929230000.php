<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Restores legacy compliance_documents values preserved by
 * app:migrations:preserve-compliance-legacy into the columns re-created by
 * Version20260929220000 (the historical Version20260824120000 dropped the
 * originals mid-chain on populated databases).
 *
 * Mapping:
 *   sha256_hash  → sha256_hash
 *   version_id   → version_id
 *   document_type → metadata_json.document_type (no direct column anymore;
 *                   kept queryable in the structured metadata)
 *
 * The archive table is RETAINED after restore (audit trail). Idempotent:
 * already-restored rows (non-null sha256_hash) are skipped.
 */
final class Version20260929230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore preserved legacy compliance values (sha256/version/document_type) into the recreated columns';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('compliance_legacy_preserved')) {
            // Fresh install (or a database where nothing needed preserving):
            // nothing to restore.
            return;
        }

        // sha256_hash + version_id restore into their re-created columns.
        $this->addSql(
            'UPDATE compliance_documents cd
             JOIN compliance_legacy_preserved p ON p.document_id = cd.id
             SET cd.sha256_hash = p.sha256_hash,
                 cd.version_id = p.version_id
             WHERE cd.sha256_hash IS NULL
               AND (p.sha256_hash IS NOT NULL OR p.version_id IS NOT NULL)'
        );

        // document_type has no direct column anymore: preserve it inside the
        // structured metadata JSON.
        $this->addSql(
            "UPDATE compliance_documents cd
             JOIN compliance_legacy_preserved p ON p.document_id = cd.id
             SET cd.metadata_json = JSON_MERGE_PATCH(
                     COALESCE(cd.metadata_json, JSON_OBJECT()),
                     JSON_OBJECT('legacy_document_type', p.document_type)
                 )
             WHERE p.document_type IS NOT NULL AND p.document_type <> ''"
        );

        // Stamp which rows came through the preservation path.
        $this->addSql(
            "UPDATE compliance_documents cd
             JOIN compliance_legacy_preserved p ON p.document_id = cd.id
             SET cd.metadata_json = JSON_MERGE_PATCH(
                     COALESCE(cd.metadata_json, JSON_OBJECT()),
                     JSON_OBJECT('legacy_preserved_at', CAST(p.preserved_at AS CHAR))
                 )
             WHERE cd.metadata_json IS NOT NULL
               AND JSON_EXTRACT(cd.metadata_json, '$.legacy_preserved_at') IS NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(true, 'Restored legacy values are kept; the archive table remains as the audit trail.');
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
