<?php

namespace App\TenantData\Support;

use Illuminate\Database\ConnectionInterface;

final class TenantExportCatalog
{
    public const FORMAT_VERSION = '1.0';

    /** @var list<string> */
    private const DATASET_TABLES = [
        'attendance_corrections',
        'attendance_records',
        'custom_field_definitions',
        'custom_field_values',
        'equipment',
        'event_enrollments',
        'event_occurrence_equipment',
        'event_occurrence_overrides',
        'event_occurrence_participants',
        'event_occurrence_rooms',
        'event_occurrence_teachers',
        'event_occurrences',
        'event_series',
        'event_series_equipment',
        'event_series_rooms',
        'event_series_splits',
        'event_series_teachers',
        'guardian_relationships',
        'household_members',
        'households',
        'instruments',
        'lesson_note_attachments',
        'lesson_note_delivery_intents',
        'lesson_note_revisions',
        'lesson_note_template_revisions',
        'lesson_note_templates',
        'lesson_notes',
        'locations',
        'people',
        'person_instruments',
        'person_tags',
        'program_offering_overrides',
        'program_offering_staff',
        'program_offerings',
        'room_equipment',
        'rooms',
        'schedule_change_events',
        'service_categories',
        'service_policies',
        'service_prices',
        'services',
        'staff_availability_overrides',
        'staff_availability_windows',
        'staff_profiles',
        'staff_scheduling_profiles',
        'staff_travel_buffers',
        'student_profiles',
        'student_status_transitions',
        'studio_audit_events',
        'studio_invitations',
        'studio_memberships',
        'studios',
        'tags',
    ];

    /** @var array<string, list<string>> */
    private const FORBIDDEN_COLUMNS = [
        'lesson_note_attachments' => [
            'storage_disk',
            'storage_path',
            'quarantine_disk',
            'quarantine_key',
            'quarantine_path',
        ],
        'scheduling_outbox_messages' => ['payload'],
        'studio_invitations' => ['token_hash', 'pending_key'],
        'studios' => ['settings'],
        'users' => [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_setup_started_at',
        ],
    ];

    /** @return list<string> */
    public function datasetTables(ConnectionInterface $connection): array
    {
        return array_values(array_filter(
            self::DATASET_TABLES,
            fn (string $table): bool => $connection->getSchemaBuilder()->hasTable($table),
        ));
    }

    /** @return list<string> */
    public function exportableColumns(ConnectionInterface $connection, string $table): array
    {
        $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        $forbidden = self::FORBIDDEN_COLUMNS[$table] ?? [];
        $columns = array_values(array_filter(
            array_diff($columns, $forbidden),
            fn (string $column): bool => ! preg_match(
                '/(^user_id$|^actor_id$|_by_id$|password|secret|token|recovery|remember|request_ip|storage_path|quarantine_(disk|key|path))/i',
                $column,
            ),
        ));
        sort($columns, SORT_STRING);

        return $columns;
    }

    /** @return list<string> */
    public function forbiddenColumnNames(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::FORBIDDEN_COLUMNS))));
    }
}
