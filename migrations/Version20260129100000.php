<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for CRM Feature Parity Implementation:
 * - Task Management
 * - Calendar Events
 * - Custom Fields
 * - Report Builder
 * - Meeting Scheduler
 */
final class Version20260129100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Task, CalendarEvent, CustomFieldDefinition, CustomFieldValue, ReportDefinition, and MeetingSlot entities for CRM feature parity';
    }

    public function up(Schema $schema): void
    {
        // This migration creates legacy singular-named tables (task,
        // calendar_event, meeting_slot, ...) that predate the canonical
        // plural names (tasks, calendar_events, meeting_slots, ...) created by
        // the baseline. It only applies to databases that never received the
        // canonical schema; on baseline/production schemas it is a no-op.
        if ($this->tableExists('tasks')) {
            return;
        }

        // ============================================================
        // TASK MANAGEMENT
        // ============================================================
        $this->addSql('CREATE TABLE IF NOT EXISTS task ( id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, assigned_to_id INT DEFAULT NULL, company_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, rfq_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL DEFAULT \'pending\', priority VARCHAR(20) NOT NULL DEFAULT \'medium\', task_type VARCHAR(50) NOT NULL DEFAULT \'general\', due_date DATETIME DEFAULT NULL, reminder_date DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, tags JSON DEFAULT NULL, metadata JSON DEFAULT NULL, INDEX IDX_task_owner (owner_id), INDEX IDX_task_assigned (assigned_to_id), INDEX IDX_task_company (company_id), INDEX IDX_task_contact (contact_id), INDEX IDX_task_rfq (rfq_id), INDEX IDX_task_lead (lead_id), INDEX IDX_task_status (status), INDEX IDX_task_priority (priority), INDEX IDX_task_due_date (due_date), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->ifConstraintMissing('tasks', 'FK_task_owner', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_owner FOREIGN KEY (owner_id) REFERENCES users (id)');
});

        $this->ifConstraintMissing('tasks', 'FK_task_assigned', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_assigned FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
});

        $this->ifConstraintMissing('tasks', 'FK_task_company', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('tasks', 'FK_task_contact', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('tasks', 'FK_task_rfq', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('tasks', 'FK_task_lead', function (): void {
    $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_task_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL');
});


        // ============================================================
        // CALENDAR EVENTS
        // ============================================================
        $this->addSql('CREATE TABLE IF NOT EXISTS calendar_event ( id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, company_id INT DEFAULT NULL, contact_id INT DEFAULT NULL, rfq_id INT DEFAULT NULL, lead_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, event_type VARCHAR(50) NOT NULL DEFAULT \'meeting\', visibility VARCHAR(20) NOT NULL DEFAULT \'private\', status VARCHAR(20) NOT NULL DEFAULT \'confirmed\', start_time DATETIME NOT NULL, end_time DATETIME NOT NULL, all_day TINYINT(1) NOT NULL DEFAULT 0, recurrence_rule VARCHAR(50) DEFAULT NULL, recurrence_end DATETIME DEFAULT NULL, parent_event_id INT DEFAULT NULL, reminder_minutes INT DEFAULT NULL, reminder_sent TINYINT(1) NOT NULL DEFAULT 0, color VARCHAR(20) DEFAULT NULL, meeting_url VARCHAR(500) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_calendar_owner (owner_id), INDEX IDX_calendar_company (company_id), INDEX IDX_calendar_contact (contact_id), INDEX IDX_calendar_rfq (rfq_id), INDEX IDX_calendar_lead (lead_id), INDEX IDX_calendar_start (start_time), INDEX IDX_calendar_end (end_time), INDEX IDX_calendar_type (event_type), INDEX IDX_calendar_parent (parent_event_id), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->addSql('CREATE TABLE IF NOT EXISTS calendar_event_attendee ( calendar_event_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_attendee_event (calendar_event_id), INDEX IDX_attendee_user (user_id), PRIMARY KEY(calendar_event_id, user_id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->ifConstraintMissing('calendar_events', 'FK_calendar_owner', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_owner FOREIGN KEY (owner_id) REFERENCES users (id)');
});

        $this->ifConstraintMissing('calendar_events', 'FK_calendar_company', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('calendar_events', 'FK_calendar_contact', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('calendar_events', 'FK_calendar_rfq', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('calendar_events', 'FK_calendar_lead', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('calendar_events', 'FK_calendar_parent', function (): void {
    $this->addSql('ALTER TABLE calendar_events ADD CONSTRAINT FK_calendar_parent FOREIGN KEY (parent_event_id) REFERENCES calendar_events (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('calendar_event_attendees', 'FK_attendee_event', function (): void {
    $this->addSql('ALTER TABLE calendar_event_attendees ADD CONSTRAINT FK_attendee_event FOREIGN KEY (calendar_event_id) REFERENCES calendar_events (id) ON DELETE CASCADE');
});

        $this->ifConstraintMissing('calendar_event_attendees', 'FK_attendee_user', function (): void {
    $this->addSql('ALTER TABLE calendar_event_attendees ADD CONSTRAINT FK_attendee_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
});


        // ============================================================
        // CUSTOM FIELDS
        // ============================================================
        $this->addSql('CREATE TABLE IF NOT EXISTS custom_field_definition ( id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, name VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, description VARCHAR(500) DEFAULT NULL, entity_type VARCHAR(50) NOT NULL, field_type VARCHAR(30) NOT NULL, field_group VARCHAR(100) DEFAULT NULL, is_required TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, is_searchable TINYINT(1) NOT NULL DEFAULT 0, is_filterable TINYINT(1) NOT NULL DEFAULT 0, show_in_list TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, options JSON DEFAULT NULL, validation_rules JSON DEFAULT NULL, default_value VARCHAR(500) DEFAULT NULL, placeholder VARCHAR(255) DEFAULT NULL, help_text VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_cfd_entity_type (entity_type), INDEX IDX_cfd_field_type (field_type), INDEX IDX_cfd_is_active (is_active), INDEX IDX_cfd_sort_order (sort_order), INDEX IDX_cfd_created_by (created_by_id), UNIQUE INDEX UNIQ_cfd_name_entity (name, entity_type), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->addSql('CREATE TABLE IF NOT EXISTS custom_field_value ( id INT AUTO_INCREMENT NOT NULL, definition_id INT NOT NULL, entity_id INT NOT NULL, text_value LONGTEXT DEFAULT NULL, number_value DECIMAL(20,6) DEFAULT NULL, date_value DATETIME DEFAULT NULL, boolean_value TINYINT(1) DEFAULT NULL, json_value JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_cfv_definition (definition_id), INDEX IDX_cfv_entity (entity_id), INDEX IDX_cfv_text (text_value(255)), INDEX IDX_cfv_number (number_value), INDEX IDX_cfv_date (date_value), UNIQUE INDEX UNIQ_cfv_definition_entity (definition_id, entity_id), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->ifConstraintMissing('custom_field_definitions', 'FK_cfd_created_by', function (): void {
    $this->addSql('ALTER TABLE custom_field_definitions ADD CONSTRAINT FK_cfd_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
});

        $this->ifConstraintMissing('custom_field_values', 'FK_cfv_definition', function (): void {
    $this->addSql('ALTER TABLE custom_field_values ADD CONSTRAINT FK_cfv_definition FOREIGN KEY (definition_id) REFERENCES custom_field_definitions (id) ON DELETE CASCADE');
});


        // ============================================================
        // REPORT BUILDER
        // ============================================================
        $this->addSql('CREATE TABLE IF NOT EXISTS report_definition ( id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, report_type VARCHAR(50) NOT NULL DEFAULT \'table\', data_source VARCHAR(50) NOT NULL, columns JSON NOT NULL, filters JSON DEFAULT NULL, group_by JSON DEFAULT NULL, order_by JSON DEFAULT NULL, date_range_type VARCHAR(30) DEFAULT NULL, date_range_start DATETIME DEFAULT NULL, date_range_end DATETIME DEFAULT NULL, chart_config JSON DEFAULT NULL, is_public TINYINT(1) NOT NULL DEFAULT 0, is_favorite TINYINT(1) NOT NULL DEFAULT 0, execution_count INT NOT NULL DEFAULT 0, last_executed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_rd_owner (owner_id), INDEX IDX_rd_data_source (data_source), INDEX IDX_rd_report_type (report_type), INDEX IDX_rd_is_public (is_public), INDEX IDX_rd_is_favorite (is_favorite), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->ifConstraintMissing('report_definitions', 'FK_rd_owner', function (): void {
    $this->addSql('ALTER TABLE report_definitions ADD CONSTRAINT FK_rd_owner FOREIGN KEY (owner_id) REFERENCES users (id)');
});


        // ============================================================
        // MEETING SCHEDULER
        // ============================================================
        $this->addSql('CREATE TABLE IF NOT EXISTS meeting_slot ( id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, meeting_type VARCHAR(50) NOT NULL DEFAULT \'introduction\', duration_minutes INT NOT NULL DEFAULT 30, location VARCHAR(255) DEFAULT NULL, meeting_url VARCHAR(500) DEFAULT NULL, meeting_provider VARCHAR(50) DEFAULT NULL, start_time DATETIME NOT NULL, end_time DATETIME NOT NULL, timezone VARCHAR(100) NOT NULL DEFAULT \'UTC\', status VARCHAR(20) NOT NULL DEFAULT \'available\', booking_token VARCHAR(100) NOT NULL, cancellation_token VARCHAR(100) DEFAULT NULL, booked_by_name VARCHAR(255) DEFAULT NULL, booked_by_email VARCHAR(255) DEFAULT NULL, booked_by_phone VARCHAR(50) DEFAULT NULL, booked_by_company VARCHAR(255) DEFAULT NULL, booking_notes LONGTEXT DEFAULT NULL, booked_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, cancellation_reason VARCHAR(500) DEFAULT NULL, confirmation_sent TINYINT(1) NOT NULL DEFAULT 0, confirmation_sent_at DATETIME DEFAULT NULL, reminder_sent TINYINT(1) NOT NULL DEFAULT 0, reminder_sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_ms_owner (owner_id), INDEX IDX_ms_start_time (start_time), INDEX IDX_ms_end_time (end_time), INDEX IDX_ms_status (status), INDEX IDX_ms_meeting_type (meeting_type), INDEX IDX_ms_booking_token (booking_token), INDEX IDX_ms_cancellation_token (cancellation_token), INDEX IDX_ms_booked_by_email (booked_by_email), PRIMARY KEY(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        
        $this->ifConstraintMissing('meeting_slots', 'FK_ms_owner', function (): void {
    $this->addSql('ALTER TABLE meeting_slots ADD CONSTRAINT FK_ms_owner FOREIGN KEY (owner_id) REFERENCES users (id)');
});

    }

    public function down(Schema $schema): void
    {
        // Drop Meeting Slot
        $this->addSql('ALTER TABLE meeting_slot DROP FOREIGN KEY FK_ms_owner');
        $this->addSql('DROP TABLE meeting_slot');
        
        // Drop Report Definition
        $this->addSql('ALTER TABLE report_definition DROP FOREIGN KEY FK_rd_owner');
        $this->addSql('DROP TABLE report_definition');
        
        // Drop Custom Fields
        $this->addSql('ALTER TABLE custom_field_value DROP FOREIGN KEY FK_cfv_definition');
        $this->addSql('DROP TABLE custom_field_value');
        $this->addSql('ALTER TABLE custom_field_definition DROP FOREIGN KEY FK_cfd_created_by');
        $this->addSql('DROP TABLE custom_field_definition');
        
        // Drop Calendar Events
        $this->addSql('ALTER TABLE calendar_event_attendee DROP FOREIGN KEY FK_attendee_event');
        $this->addSql('ALTER TABLE calendar_event_attendee DROP FOREIGN KEY FK_attendee_user');
        $this->addSql('DROP TABLE calendar_event_attendee');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_owner');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_company');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_contact');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_rfq');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_lead');
        $this->addSql('ALTER TABLE calendar_event DROP FOREIGN KEY FK_calendar_parent');
        $this->addSql('DROP TABLE calendar_event');
        
        // Drop Tasks
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_owner');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_assigned');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_company');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_contact');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_rfq');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_lead');
        $this->addSql('DROP TABLE task');
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
