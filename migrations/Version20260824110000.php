<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Consistency and duplicate-prevention migration:
 *
 * 1. Align tariff_rates/freight_tables is_active defaults to true (matching the
 *    other dataset entities) so newly created rates are active by default.
 * 2. Add an index on playbook_runs.status (used by findPending).
 * 3. Merge duplicate webinar_attendees (webinar_id, email) rows — keeping the
 *    earliest registration and OR-ing attended/follow_up_sent state — then add
 *    a UNIQUE index so double registrations are impossible.
 * 4. Merge duplicate personalization_profiles rows per contact_id — keeping the
 *    most recently updated record, summing counters and merging history — then
 *    add a UNIQUE index on contact_id so concurrent creates cannot duplicate.
 *
 * All data is preserved (no rows are dropped without merging their state first).
 * Every statement is guarded so the migration is idempotent against the
 * entity-generated test schema.
 */
final class Version20260824110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Active-by-default tariff/freight rates, playbook_runs.status index, webinar_attendees and personalization_profiles dedupe + unique indexes';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->alignIsActiveDefaults();
        $this->addPlaybookRunStatusIndex();
        $this->mergeWebinarAttendeeDuplicates();
        $this->addWebinarAttendeeUniqueIndex();
        $this->mergePersonalizationProfileDuplicates();
        $this->addPersonalizationProfileUniqueIndex();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tariff_rates ALTER COLUMN is_active SET DEFAULT 0');
        $this->addSql('ALTER TABLE freight_tables ALTER COLUMN is_active SET DEFAULT 0');
        $this->dropIndexIfExists('webinar_attendees', 'uniq_webinar_email');
        $this->dropIndexIfExists('personalization_profiles', 'uniq_pers_contact');
        $this->dropIndexIfExists('playbook_runs', 'idx_playbook_runs_status');
    }

    private function alignIsActiveDefaults(): void
    {
        foreach (['tariff_rates', 'freight_tables'] as $table) {
            if (!$this->columnExists($table, 'is_active')) {
                $this->addSql("ALTER TABLE {$table} ADD is_active TINYINT(1) DEFAULT 1 NOT NULL");
            } else {
                $this->addSql("ALTER TABLE {$table} ALTER COLUMN is_active SET DEFAULT 1");
            }
        }
    }

    private function addPlaybookRunStatusIndex(): void
    {
        if (!$this->indexExists('playbook_runs', 'idx_playbook_runs_status')) {
            $this->addSql('CREATE INDEX idx_playbook_runs_status ON playbook_runs (status)');
        }
    }

    private function mergeWebinarAttendeeDuplicates(): void
    {
        $conn = $this->connection;
        $dupes = $conn->fetchAllAssociative(
            'SELECT webinar_id, email
             FROM webinar_attendees
             WHERE email IS NOT NULL
             GROUP BY webinar_id, email
             HAVING COUNT(*) > 1'
        );

        foreach ($dupes as $dupe) {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, attended, follow_up_sent
                 FROM webinar_attendees
                 WHERE webinar_id = ? AND email = ?
                 ORDER BY registered_at ASC, id ASC',
                [$dupe['webinar_id'], $dupe['email']]
            );

            if (count($rows) < 2) {
                continue;
            }

            $keeper = array_shift($rows);
            $attended = (bool) $keeper['attended'];
            $followUpSent = (bool) $keeper['follow_up_sent'];
            $deleteIds = [];

            foreach ($rows as $row) {
                $attended = $attended || (bool) $row['attended'];
                $followUpSent = $followUpSent || (bool) $row['follow_up_sent'];
                $deleteIds[] = (int) $row['id'];
            }

            $conn->executeStatement(
                'UPDATE webinar_attendees SET attended = ?, follow_up_sent = ? WHERE id = ?',
                [$attended, $followUpSent, $keeper['id']]
            );

            $conn->executeStatement(
                'DELETE FROM webinar_attendees WHERE id IN (' . implode(',', array_fill(0, count($deleteIds), '?')) . ')',
                $deleteIds
            );
        }
    }

    private function addWebinarAttendeeUniqueIndex(): void
    {
        if (!$this->indexExists('webinar_attendees', 'uniq_webinar_email')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_webinar_email ON webinar_attendees (webinar_id, email)');
        }
    }

    private function mergePersonalizationProfileDuplicates(): void
    {
        $conn = $this->connection;
        $dupes = $conn->fetchAllAssociative(
            'SELECT contact_id
             FROM personalization_profiles
             WHERE contact_id IS NOT NULL
             GROUP BY contact_id
             HAVING COUNT(*) > 1'
        );

        foreach ($dupes as $dupe) {
            $rows = $conn->fetchAllAssociative(
                'SELECT *
                 FROM personalization_profiles
                 WHERE contact_id = ?
                 ORDER BY COALESCE(updated_at, created_at) DESC, id DESC',
                [$dupe['contact_id']]
            );

            if (count($rows) < 2) {
                continue;
            }

            $keeper = array_shift($rows);

            foreach (['emails_opened', 'emails_replied', 'emails_bounced', 'links_clicked'] as $counter) {
                foreach ($rows as $row) {
                    $keeper[$counter] += (int) ($row[$counter] ?? 0);
                }
            }

            $interactionHistory = $this->decodeJson($keeper['interaction_history'] ?? null);
            $topicInterests = $this->decodeJson($keeper['topic_interests'] ?? null);
            $avoidTopics = $this->decodeJson($keeper['avoid_topics'] ?? null);
            $successfulSubjectPatterns = $this->decodeJson($keeper['successful_subject_patterns'] ?? null);
            $metadata = $this->decodeJson($keeper['metadata'] ?? null);
            $featureEmbedding = $this->decodeJson($keeper['feature_embedding'] ?? null);

            foreach ($rows as $row) {
                $history = $this->decodeJson($row['interaction_history'] ?? null);
                foreach ($history as $entry) {
                    $interactionHistory[] = $entry;
                }

                foreach ($this->decodeJson($row['topic_interests'] ?? null) as $topic => $weight) {
                    $topicInterests[$topic] = max($topicInterests[$topic] ?? 0.0, (float) $weight);
                }

                foreach ($this->decodeJson($row['avoid_topics'] ?? null) as $topic) {
                    if (!in_array($topic, $avoidTopics, true)) {
                        $avoidTopics[] = $topic;
                    }
                }

                foreach ($this->decodeJson($row['successful_subject_patterns'] ?? null) as $pattern) {
                    if (!in_array($pattern, $successfulSubjectPatterns, true)) {
                        $successfulSubjectPatterns[] = $pattern;
                    }
                }

                $rowMetadata = $this->decodeJson($row['metadata'] ?? null);
                foreach ($rowMetadata as $key => $value) {
                    if (!array_key_exists($key, $metadata)) {
                        $metadata[$key] = $value;
                    }
                }

                if ($featureEmbedding === [] && $this->decodeJson($row['feature_embedding'] ?? null) !== []) {
                    $featureEmbedding = $this->decodeJson($row['feature_embedding'] ?? null);
                }

                if (($keeper['best_send_time'] ?? null) === null && ($row['best_send_time'] ?? null) !== null) {
                    $keeper['best_send_time'] = $row['best_send_time'];
                }
                if (($keeper['best_send_day'] ?? null) === null && ($row['best_send_day'] ?? null) !== null) {
                    $keeper['best_send_day'] = $row['best_send_day'];
                }
            }

            $interactionHistory = array_values(array_filter($interactionHistory, 'is_array'));
            usort(
                $interactionHistory,
                static fn (array $a, array $b): int => (int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0)
            );
            $interactionHistory = array_slice($interactionHistory, 0, 50);

            $conn->executeStatement(
                'UPDATE personalization_profiles
                 SET emails_opened = ?, emails_replied = ?, emails_bounced = ?, links_clicked = ?,
                     topic_interests = ?, avoid_topics = ?, interaction_history = ?,
                     successful_subject_patterns = ?, metadata = ?, feature_embedding = ?,
                     best_send_time = ?, best_send_day = ?
                 WHERE id = ?',
                [
                    $keeper['emails_opened'],
                    $keeper['emails_replied'],
                    $keeper['emails_bounced'],
                    $keeper['links_clicked'],
                    json_encode($topicInterests),
                    json_encode(array_values($avoidTopics)),
                    json_encode(array_values($interactionHistory)),
                    json_encode(array_values($successfulSubjectPatterns)),
                    json_encode($metadata),
                    json_encode($featureEmbedding),
                    $keeper['best_send_time'],
                    $keeper['best_send_day'],
                    $keeper['id'],
                ]
            );

            $deleteIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $conn->executeStatement(
                'DELETE FROM personalization_profiles WHERE id IN (' . implode(',', array_fill(0, count($deleteIds), '?')) . ')',
                $deleteIds
            );
        }
    }

    private function addPersonalizationProfileUniqueIndex(): void
    {
        if (!$this->indexExists('personalization_profiles', 'uniq_pers_contact')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_pers_contact ON personalization_profiles (contact_id)');
        }
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->addSql("DROP INDEX {$indexName} ON {$table}");
        }
    }
}
