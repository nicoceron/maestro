<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDomainCommand;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventSeries;
use App\Models\LessonNote;
use App\Models\LessonNoteDeliveryIntent;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\LessonNoteRevision;
use App\Models\LessonNoteTemplate;
use App\Models\LessonNoteTemplateRevision;
use App\Models\Location;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttendanceNotesPostgresTest extends TestCase
{
    /** @var list<string> */
    private const TABLES = [
        'attendance_records', 'attendance_corrections', 'lesson_note_templates',
        'lesson_note_template_revisions', 'lesson_notes', 'lesson_note_revisions',
        'lesson_note_delivery_previews', 'lesson_note_delivery_intents', 'attendance_domain_commands',
    ];

    public function test_every_attendance_table_is_forced_rls_default_deny_and_tenant_scoped(): void
    {
        $this->requirePostgres();
        [$runtime, $runtimeName] = $this->runtimeConnection();
        $first = $this->seedTenant();
        $second = $this->seedTenant();

        try {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, $runtime->table($table)->count(), "{$table} must default deny.");
            }

            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$first['studio']->getKey()]);
            foreach (self::TABLES as $table) {
                $this->assertSame(1, $runtime->table($table)->count(), "{$table} leaked another studio.");
            }

            $this->assertRejected(function () use ($runtime, $first, $second): void {
                $runtime->table('attendance_domain_commands')->insert([
                    'id' => (string) Str::ulid(), 'studio_id' => $second['studio']->getKey(),
                    'actor_id' => $first['user']->getKey(), 'operation' => 'cross-tenant',
                    'idempotency_key' => 'cross-tenant', 'command_hash' => str_repeat('a', 64),
                    'result_projection' => '{}', 'created_at' => now(),
                ]);
            });
            $this->assertRejected(function () use ($runtime, $first, $second): void {
                $runtime->table('attendance_records')->insert([
                    'id' => (string) Str::ulid(), 'studio_id' => $first['studio']->getKey(),
                    'event_occurrence_id' => $second['occurrence']->getKey(),
                    'event_occurrence_participant_id' => $second['participant']->getKey(),
                    'person_id' => $second['person']->getKey(), 'outcome' => 'present',
                    'billing_disposition' => 'bill', 'makeup_disposition' => 'none',
                    'minutes_late' => 0, 'recorded_by_user_id' => $first['user']->getKey(),
                    'recorded_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } finally {
            DB::purge($runtimeName);
        }
    }

    public function test_postgres_guards_immutable_history_and_projection_transitions(): void
    {
        $this->requirePostgres();
        $fixture = $this->seedTenant();

        foreach ([$fixture['correction'], $fixture['templateRevision'], $fixture['noteRevision'], $fixture['intent'], $fixture['command']] as $model) {
            $this->assertRejected(fn () => DB::table($model->getTable())->where('id', $model->getKey())
                ->update(['id' => (string) Str::ulid()]));
        }
        foreach ([$fixture['attendance'], $fixture['template'], $fixture['note']] as $model) {
            $this->assertRejected(fn () => DB::table($model->getTable())->where('id', $model->getKey())
                ->update(['version' => 99]));
            $this->assertRejected(fn () => DB::table($model->getTable())->where('id', $model->getKey())->delete());
        }
    }

    /** @return array<string, mixed> */
    private function seedTenant(): array
    {
        $studio = Studio::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->owner()->create(['studio_id' => $studio->getKey(), 'user_id' => $user->getKey()]);
        $location = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'RLS '.Str::ulid(), 'kind' => 'physical', 'timezone' => 'UTC',
        ]);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'kind' => 'general',
            'status' => 'active', 'visibility' => 'private', 'title' => 'Security fixture', 'timezone' => 'UTC',
            'dtstart_local' => '2027-01-01T10:00:00', 'duration_minutes' => 60, 'capacity' => 2,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'location_id' => $location->getKey(),
            'public_uid' => (string) Str::uuid(), 'recurrence_id_local' => '2027-01-01T10:00:00',
            'starts_at' => '2027-01-01 10:00:00+00', 'ends_at' => '2027-01-01 11:00:00+00',
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'status' => 'completed', 'title' => 'Security fixture',
            'kind' => 'general', 'capacity' => 2, 'price_minor' => 0, 'currency' => 'USD',
        ]);
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $participant = EventOccurrenceParticipant::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_series_id' => $series->getKey(), 'person_id' => $person->getKey(), 'role' => 'student',
            'status' => 'confirmed', 'blocks_conflicts' => true,
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
        $recordedAt = now();
        $attendance = AttendanceRecord::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_occurrence_participant_id' => $participant->getKey(), 'person_id' => $person->getKey(),
            'outcome' => 'present', 'billing_disposition' => 'bill', 'makeup_disposition' => 'none',
            'recorded_by_user_id' => $user->getKey(), 'recorded_at' => $recordedAt,
        ]);
        $snapshot = [
            'outcome' => 'present', 'billing_disposition' => 'bill', 'makeup_disposition' => 'none',
            'minutes_late' => 0, 'reason' => null, 'recorded_by_user_id' => $user->getKey(),
            'recorded_at' => $recordedAt->toAtomString(),
        ];
        $correction = AttendanceCorrection::query()->create([
            'studio_id' => $studio->getKey(), 'attendance_record_id' => $attendance->getKey(),
            'previous_version' => 1, 'previous_values' => $snapshot, 'new_values' => $snapshot,
            'reason' => 'Security fixture.', 'actor_id' => $user->getKey(), 'occurred_at' => now(),
        ]);
        $template = LessonNoteTemplate::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Security '.Str::ulid(),
            'audience' => 'student', 'body_html' => '<p>Template</p>',
        ]);
        $templateRevision = LessonNoteTemplateRevision::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_template_id' => $template->getKey(),
            'revision' => 1, 'name' => $template->name, 'audience' => 'student',
            'body_html' => $template->body_html, 'active' => true, 'reason' => 'Created.',
            'actor_id' => $user->getKey(), 'created_at' => now(),
        ]);
        $note = LessonNote::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_occurrence_participant_id' => $participant->getKey(), 'person_id' => $person->getKey(),
            'author_user_id' => $user->getKey(), 'scope' => 'participant', 'audience' => 'student',
            'body_html' => '<p>Note</p>',
        ]);
        $noteRevision = LessonNoteRevision::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(), 'revision' => 1,
            'body_html' => $note->body_html, 'actor_id' => $user->getKey(), 'created_at' => now(),
        ]);
        $recipientHash = hash('sha256', '[]');
        $preview = LessonNoteDeliveryPreview::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(), 'actor_id' => $user->getKey(),
            'note_version' => 1, 'command_hash' => str_repeat('a', 64), 'recipient_hash' => $recipientHash,
            'recipient_projection' => ['user_ids' => [], 'recipient_count' => 0], 'expires_at' => now()->addMinutes(10),
        ]);
        $intent = LessonNoteDeliveryIntent::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(),
            'delivery_preview_id' => $preview->getKey(), 'actor_id' => $user->getKey(),
            'idempotency_key' => 'security-fixture', 'recipient_hash' => $recipientHash,
            'recipient_projection' => ['user_ids' => [], 'recipient_count' => 0],
            'note_version' => 1, 'status' => 'committed', 'committed_at' => now(),
        ]);
        $command = AttendanceDomainCommand::query()->create([
            'studio_id' => $studio->getKey(), 'actor_id' => $user->getKey(), 'operation' => 'security-fixture',
            'idempotency_key' => 'security-fixture', 'command_hash' => str_repeat('b', 64),
            'result_projection' => ['ok' => true], 'created_at' => now(),
        ]);

        return compact('studio', 'user', 'occurrence', 'participant', 'person', 'attendance', 'correction', 'template', 'templateRevision', 'note', 'noteRevision', 'preview', 'intent', 'command');
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }
        $name = 'pgsql_runtime_attendance_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$name}" => array_replace(
            config('database.connections.pgsql'), ['username' => $username, 'password' => $password],
        )]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required.');
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted a forbidden attendance operation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
