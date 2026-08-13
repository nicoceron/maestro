<?php

namespace Tests\Feature\Database;

use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventSeries;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentCommand;
use App\Models\LessonNoteAttachmentPurge;
use App\Models\LessonNoteAttachmentRetirement;
use App\Models\LessonNoteAttachmentScan;
use App\Models\LessonNoteRevision;
use App\Models\Location;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LessonNoteAttachmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attachment_schema_rolls_back_and_remigrates_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'maestro-attachments-');
        $this->assertIsString($path);
        $connection = 'attachments_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]));
            $schema = DB::connection($connection)->getSchemaBuilder();
            $this->assertTrue($schema->hasTable('lesson_note_attachments'));
            $this->assertTrue($schema->hasTable('lesson_note_attachment_scans'));
            $this->assertTrue($schema->hasTable('lesson_note_attachment_retirements'));

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => $connection,
                '--path' => 'database/migrations/2026_08_13_130000_create_lesson_note_attachments.php',
                '--force' => true,
            ]));
            $this->assertFalse($schema->hasTable('lesson_note_attachments'));
            $this->assertTrue($schema->hasTable('lesson_notes'));

            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
            $this->assertTrue($schema->hasTable('lesson_note_attachments'));
            $this->assertTrue($schema->hasTable('lesson_note_attachment_scans'));
            $this->assertTrue($schema->hasTable('lesson_note_attachment_retirements'));
        } finally {
            DB::purge($connection);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function test_sqlite_guards_invalid_scan_state_and_immutable_attachment_history(): void
    {
        [$attachment, $user] = $this->attachmentFixture();

        DB::statement('SAVEPOINT attachment_invalid_scan');
        try {
            DB::table('lesson_note_attachment_scans')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $attachment->studio_id,
                'lesson_note_attachment_id' => $attachment->getKey(),
                'attempt' => 1,
                'status' => 'clean',
                'engine' => 'fake',
                'detail_code' => 'clean',
                'clean_disk' => null,
                'clean_key' => null,
                'scanned_at' => now(),
            ]);
            DB::statement('RELEASE SAVEPOINT attachment_invalid_scan');
            $this->fail('SQLite accepted a clean scan without a clean object.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT attachment_invalid_scan');
            DB::statement('RELEASE SAVEPOINT attachment_invalid_scan');
            $this->assertMatchesRegularExpression(
                '/invalid lesson note attachment scan|lesson_note_attachment_scans_state_check/i',
                $exception->getMessage(),
            );
        }

        $scan = LessonNoteAttachmentScan::query()->create([
            'studio_id' => $attachment->studio_id,
            'lesson_note_attachment_id' => $attachment->getKey(),
            'attempt' => 1,
            'status' => 'failed',
            'engine' => 'deterministic',
            'detail_code' => 'scanner_unavailable',
            'scanned_at' => now(),
        ]);
        $retirement = LessonNoteAttachmentRetirement::query()->create([
            'studio_id' => $attachment->studio_id,
            'lesson_note_id' => $attachment->lesson_note_id,
            'lesson_note_attachment_id' => $attachment->getKey(),
            'retired_by_user_id' => $user->getKey(),
            'reason' => 'Retired in database guard fixture.',
            'retired_at' => now(),
        ]);
        $command = LessonNoteAttachmentCommand::query()->create([
            'studio_id' => $attachment->studio_id,
            'lesson_note_id' => $attachment->lesson_note_id,
            'lesson_note_attachment_id' => $attachment->getKey(),
            'actor_id' => $user->getKey(),
            'idempotency_key' => 'sqlite-guard',
            'command_hash' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
        $purge = LessonNoteAttachmentPurge::query()->create([
            'studio_id' => $attachment->studio_id,
            'lesson_note_attachment_id' => $attachment->getKey(),
            'object_kind' => 'quarantine',
            'disk' => 'lesson_attachments',
            'object_key' => $attachment->quarantine_key,
            'reason' => 'retired_retention_elapsed',
            'purged_at' => now(),
        ]);

        foreach ([$attachment, $scan, $retirement, $command, $purge] as $record) {
            $this->assertMutationRejected(fn () => DB::table($record->getTable())
                ->where('id', $record->getKey())->update(['id' => (string) Str::ulid()]));
            $this->assertMutationRejected(fn () => DB::table($record->getTable())
                ->where('id', $record->getKey())->delete());
        }
    }

    /** @return array{LessonNoteAttachment, User} */
    private function attachmentFixture(): array
    {
        $studio = Studio::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->owner()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $user->getKey(),
        ]);
        $location = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => 'Attachment fixture', 'kind' => 'physical', 'timezone' => 'UTC',
        ]);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'kind' => 'general',
            'status' => 'active', 'visibility' => 'private', 'title' => 'Attachment fixture', 'timezone' => 'UTC',
            'dtstart_local' => '2027-01-01T10:00:00', 'duration_minutes' => 60, 'capacity' => 1,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'location_id' => $location->getKey(),
            'public_uid' => Str::uuid(), 'recurrence_id_local' => '2027-01-01T10:00:00',
            'starts_at' => '2027-01-01 10:00:00+00', 'ends_at' => '2027-01-01 11:00:00+00',
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'status' => 'completed', 'title' => 'Attachment fixture',
            'kind' => 'general', 'capacity' => 1, 'price_minor' => 0, 'currency' => 'USD',
        ]);
        $person = Person::factory()->create(['studio_id' => $studio->getKey()]);
        $participant = EventOccurrenceParticipant::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_series_id' => $series->getKey(), 'person_id' => $person->getKey(),
            'role' => 'student', 'status' => 'confirmed', 'blocks_conflicts' => true,
            'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
        ]);
        $note = LessonNote::query()->create([
            'studio_id' => $studio->getKey(), 'event_occurrence_id' => $occurrence->getKey(),
            'event_occurrence_participant_id' => $participant->getKey(), 'person_id' => $person->getKey(),
            'author_user_id' => $user->getKey(), 'scope' => 'participant', 'audience' => 'student',
            'body_html' => '<p>Attachment fixture</p>',
        ]);
        $revision = LessonNoteRevision::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(), 'revision' => 1,
            'body_html' => $note->body_html, 'actor_id' => $user->getKey(), 'created_at' => now(),
        ]);
        $key = "quarantine/{$studio->getKey()}/{$note->getKey()}/".str_repeat('a', 40).'.pdf';
        $attachment = LessonNoteAttachment::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(),
            'lesson_note_revision_id' => $revision->getKey(), 'uploaded_by_user_id' => $user->getKey(),
            'quarantine_disk' => 'lesson_attachments', 'quarantine_key' => $key,
            'original_name' => 'fixture.pdf', 'extension' => 'pdf', 'declared_mime' => 'application/pdf',
            'detected_mime' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);

        return [$attachment, $user];
    }

    private function assertMutationRejected(callable $operation): void
    {
        DB::statement('SAVEPOINT attachment_immutable_guard');
        try {
            $operation();
            DB::statement('RELEASE SAVEPOINT attachment_immutable_guard');
            $this->fail('SQLite accepted an immutable attachment history mutation.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT attachment_immutable_guard');
            DB::statement('RELEASE SAVEPOINT attachment_immutable_guard');
            $this->assertStringContainsString('immutable', mb_strtolower($exception->getMessage()));
        }
    }
}
