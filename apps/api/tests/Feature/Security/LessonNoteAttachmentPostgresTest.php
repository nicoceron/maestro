<?php

namespace Tests\Feature\Security;

use App\Actions\Attendance\ScanLessonNoteAttachment;
use App\Contracts\Attachments\MalwareScanner;
use App\Jobs\ScanLessonNoteAttachmentJob;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventSeries;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentCommand;
use App\Models\LessonNoteAttachmentPurge;
use App\Models\LessonNoteAttachmentPurgeClaim;
use App\Models\LessonNoteAttachmentRetirement;
use App\Models\LessonNoteAttachmentScan;
use App\Models\LessonNoteRevision;
use App\Models\Location;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Attachments\MalwareScanResult;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class LessonNoteAttachmentPostgresTest extends TestCase
{
    /** @var list<string> */
    private const TABLES = [
        'lesson_note_attachments',
        'lesson_note_attachment_scans',
        'lesson_note_attachment_retirements',
        'lesson_note_attachment_commands',
        'lesson_note_attachment_purge_claims',
        'lesson_note_attachment_purges',
    ];

    public function test_every_attachment_table_forces_rls_defaults_deny_and_is_tenant_scoped(): void
    {
        $this->requirePostgres();
        [$runtime, $runtimeName] = $this->runtimeConnection();
        $first = $this->seedTenant('first');
        $second = $this->seedTenant('second');

        try {
            foreach (self::TABLES as $table) {
                $security = DB::table('pg_class')->where('relname', $table)
                    ->first(['relrowsecurity', 'relforcerowsecurity']);
                $this->assertTrue((bool) $security?->relrowsecurity, "{$table} must enable RLS.");
                $this->assertTrue((bool) $security?->relforcerowsecurity, "{$table} must force RLS.");
                $this->assertSame(0, $runtime->table($table)->count(), "{$table} must default deny.");
            }

            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$first['studio']->getKey()]);
            foreach (self::TABLES as $table) {
                $this->assertSame(1, $runtime->table($table)->count(), "{$table} leaked another studio.");
            }

            $this->assertRejected(fn () => $runtime->table('lesson_note_attachment_commands')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $second['studio']->getKey(),
                'lesson_note_id' => $second['note']->getKey(),
                'lesson_note_attachment_id' => $second['attachment']->getKey(),
                'actor_id' => $first['user']->getKey(),
                'idempotency_key' => 'cross-tenant',
                'command_hash' => str_repeat('c', 64),
                'created_at' => now(),
            ]));
            $this->assertRejected(fn () => $runtime->table('lesson_note_attachment_scans')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $first['studio']->getKey(),
                'lesson_note_attachment_id' => $second['attachment']->getKey(),
                'attempt' => 2,
                'status' => 'failed',
                'engine' => 'deterministic',
                'detail_code' => 'cross_tenant',
                'scanned_at' => now(),
            ]));
        } finally {
            DB::purge($runtimeName);
        }
    }

    public function test_postgres_guards_lineage_state_immutability_and_idempotency_claims(): void
    {
        $this->requirePostgres();
        $first = $this->seedTenant('guards');
        $otherNote = LessonNote::query()->create([
            'studio_id' => $first['studio']->getKey(),
            'event_occurrence_id' => $first['occurrence']->getKey(),
            'author_user_id' => $first['user']->getKey(),
            'scope' => 'group', 'audience' => 'student', 'body_html' => '<p>Other note</p>',
        ]);
        $otherRevision = LessonNoteRevision::query()->create([
            'studio_id' => $first['studio']->getKey(), 'lesson_note_id' => $otherNote->getKey(),
            'revision' => 1, 'body_html' => $otherNote->body_html,
            'actor_id' => $first['user']->getKey(), 'created_at' => now(),
        ]);

        foreach (['attachment', 'scan', 'retirement', 'command', 'purge'] as $key) {
            $record = $first[$key];
            $this->assertRejected(fn () => DB::table($record->getTable())
                ->where('id', $record->getKey())->update(['id' => (string) Str::ulid()]));
            $this->assertRejected(fn () => DB::table($record->getTable())
                ->where('id', $record->getKey())->delete());
        }

        $this->assertRejected(fn () => DB::table('lesson_note_attachment_scans')->insert([
            'id' => (string) Str::ulid(),
            'studio_id' => $first['studio']->getKey(),
            'lesson_note_attachment_id' => $first['attachment']->getKey(),
            'attempt' => 2,
            'status' => 'clean',
            'engine' => 'deterministic',
            'detail_code' => 'clean',
            'clean_disk' => null,
            'clean_key' => null,
            'scanned_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('lesson_note_attachment_commands')->insert([
            'id' => (string) Str::ulid(),
            'studio_id' => $first['studio']->getKey(),
            'lesson_note_id' => $otherNote->getKey(),
            'lesson_note_attachment_id' => $first['attachment']->getKey(),
            'actor_id' => $first['user']->getKey(),
            'idempotency_key' => 'cross-note-lineage',
            'command_hash' => str_repeat('d', 64),
            'created_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('lesson_note_attachments')->insert([
            'id' => (string) Str::ulid(),
            'studio_id' => $first['studio']->getKey(),
            'lesson_note_id' => $first['note']->getKey(),
            'lesson_note_revision_id' => $otherRevision->getKey(),
            'uploaded_by_user_id' => $first['user']->getKey(),
            'quarantine_disk' => 'lesson_attachments',
            'quarantine_key' => "quarantine/{$first['studio']->getKey()}/{$first['note']->getKey()}/".str_repeat('f', 40).'.pdf',
            'original_name' => 'cross-revision.pdf', 'extension' => 'pdf',
            'declared_mime' => 'application/pdf', 'detected_mime' => 'application/pdf',
            'size_bytes' => 10, 'sha256' => str_repeat('f', 64), 'created_at' => now(),
        ]));
        $this->assertRejected(fn () => DB::table('lesson_note_attachment_commands')->insert([
            'id' => (string) Str::ulid(),
            'studio_id' => $first['studio']->getKey(),
            'lesson_note_id' => $first['note']->getKey(),
            'lesson_note_attachment_id' => $first['attachment']->getKey(),
            'actor_id' => $first['user']->getKey(),
            'idempotency_key' => $first['command']->idempotency_key,
            'command_hash' => str_repeat('e', 64),
            'created_at' => now(),
        ]));
    }

    public function test_scan_worker_uses_session_tenant_context_and_always_clears_it(): void
    {
        $this->requirePostgres();
        $fixture = $this->seedTenant('worker-context', retired: false);
        DB::statement("select set_config('app.current_studio_id', '', false)");
        $scanner = new class implements MalwareScanner
        {
            public function scan($stream): MalwareScanResult
            {
                return MalwareScanResult::failed('deterministic');
            }
        };
        $scan = new ScanLessonNoteAttachment($scanner);
        $job = new ScanLessonNoteAttachmentJob(
            (string) $fixture['studio']->getKey(),
            (string) $fixture['attachment']->getKey(),
        );
        $job->handle($scan, app(RequestDatabaseContext::class));
        $this->assertSame('', DB::scalar("select current_setting('app.current_studio_id', true)"));

        try {
            (new ScanLessonNoteAttachmentJob(
                (string) $fixture['studio']->getKey(),
                (string) Str::ulid(),
            ))->handle($scan, app(RequestDatabaseContext::class));
            $this->fail('A missing attachment should fail the scan job.');
        } catch (ModelNotFoundException) {
            $this->assertSame('', DB::scalar("select current_setting('app.current_studio_id', true)"));
        }
    }

    public function test_concurrent_upload_retries_share_one_idempotent_attachment(): void
    {
        $this->requirePostgres();
        $fixture = $this->seedTenant('concurrent-upload', retired: false);
        $source = tempnam(sys_get_temp_dir(), 'maestro-upload-source-');
        $this->assertIsString($source);
        file_put_contents($source, "%PDF-1.7\nconcurrent attachment\n%%EOF");
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $note = App\Models\LessonNote::query()->findOrFail($argv[2]);
            $user = App\Models\User::query()->findOrFail((int) $argv[3]);
            $file = new Illuminate\Http\UploadedFile($argv[4], 'concurrent.pdf', 'application/pdf', null, true);
            $attachment = $app->make(App\Actions\Attendance\UploadLessonNoteAttachment::class)
                ->handle($note, $file, 1, $user, 'pg-concurrent-action-retry');
            echo $attachment->getKey();
            PHP;
        $processes = [
            new Process([PHP_BINARY, '-r', $code, base_path(), $fixture['note']->getKey(), $fixture['user']->getKey(), $source]),
            new Process([PHP_BINARY, '-r', $code, base_path(), $fixture['note']->getKey(), $fixture['user']->getKey(), $source]),
        ];

        try {
            foreach ($processes as $process) {
                $process->setTimeout(30)->start();
            }
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $ids = array_map(fn (Process $process): string => trim($process->getOutput()), $processes);
            $this->assertNotSame('', $ids[0]);
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, LessonNoteAttachmentCommand::query()
                ->where('studio_id', $fixture['studio']->getKey())
                ->where('idempotency_key', 'pg-concurrent-action-retry')->count());
            $this->assertSame(1, LessonNoteAttachment::query()->whereKey($ids[0])->count());
            $paths = LessonNoteAttachment::query()->where('studio_id', $fixture['studio']->getKey())
                ->where('lesson_note_id', $fixture['note']->getKey())
                ->whereKeyNot($fixture['attachment']->getKey())->pluck('quarantine_key');
            $this->assertCount(1, $paths);
            foreach ($paths as $path) {
                Storage::disk('lesson_attachments')->delete($path);
            }
        } finally {
            if (file_exists($source)) {
                unlink($source);
            }
        }
    }

    /** @return array<string, mixed> */
    private function seedTenant(string $suffix, bool $retired = true): array
    {
        $studio = Studio::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);
        StudioMembership::factory()->owner()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $user->getKey(),
        ]);
        $location = Location::query()->create([
            'studio_id' => $studio->getKey(), 'name' => "Attachment {$suffix}", 'kind' => 'physical', 'timezone' => 'UTC',
        ]);
        $series = EventSeries::query()->create([
            'studio_id' => $studio->getKey(), 'location_id' => $location->getKey(), 'kind' => 'general',
            'status' => 'active', 'visibility' => 'private', 'title' => "Attachment {$suffix}", 'timezone' => 'UTC',
            'dtstart_local' => '2027-01-01T10:00:00', 'duration_minutes' => 60, 'capacity' => 1,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'location_id' => $location->getKey(),
            'public_uid' => Str::uuid(), 'recurrence_id_local' => '2027-01-01T10:00:00',
            'starts_at' => '2027-01-01 10:00:00+00', 'ends_at' => '2027-01-01 11:00:00+00',
            'utc_offset_minutes' => 0, 'timezone' => 'UTC', 'status' => 'completed', 'title' => "Attachment {$suffix}",
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
        $scan = LessonNoteAttachmentScan::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_attachment_id' => $attachment->getKey(),
            'attempt' => 1, 'status' => 'failed', 'engine' => 'deterministic',
            'detail_code' => 'scanner_unavailable', 'scanned_at' => now(),
        ]);
        $retirement = $retired ? LessonNoteAttachmentRetirement::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(),
            'lesson_note_attachment_id' => $attachment->getKey(), 'retired_by_user_id' => $user->getKey(),
            'reason' => 'PostgreSQL security fixture.', 'retired_at' => now(),
        ]) : null;
        $command = LessonNoteAttachmentCommand::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_id' => $note->getKey(),
            'lesson_note_attachment_id' => $attachment->getKey(), 'actor_id' => $user->getKey(),
            'idempotency_key' => "pg-{$suffix}", 'command_hash' => str_repeat('c', 64), 'created_at' => now(),
        ]);
        $purge = LessonNoteAttachmentPurge::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_attachment_id' => $attachment->getKey(),
            'object_kind' => 'quarantine', 'disk' => 'lesson_attachments', 'object_key' => $key,
            'reason' => 'retired_retention_elapsed', 'purged_at' => now(),
        ]);

        $claim = LessonNoteAttachmentPurgeClaim::query()->create([
            'studio_id' => $studio->getKey(), 'lesson_note_attachment_id' => $attachment->getKey(),
            'object_kind' => 'quarantine', 'disk' => 'lesson_attachments', 'object_key' => $key,
            'reason' => 'retired_retention_elapsed', 'status' => 'completed',
            'claimed_at' => now()->subMinute(), 'completed_at' => now(),
        ]);

        return compact('studio', 'user', 'occurrence', 'note', 'attachment', 'scan', 'retirement', 'command', 'purge', 'claim');
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }
        $name = 'pgsql_runtime_attachments_'.Str::lower((string) Str::ulid());
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
            $this->fail('PostgreSQL accepted a forbidden attachment operation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
