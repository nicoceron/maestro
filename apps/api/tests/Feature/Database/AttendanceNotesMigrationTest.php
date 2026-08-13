<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttendanceNotesMigrationTest extends TestCase
{
    public function test_attendance_and_notes_roll_back_and_remigrate_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'maestro-attendance-');
        $this->assertIsString($path);
        $connection = 'attendance_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]));
            $schema = DB::connection($connection)->getSchemaBuilder();
            $this->assertTrue($schema->hasTable('attendance_records'));
            $this->assertTrue($schema->hasTable('attendance_domain_commands'));
            $this->assertTrue($schema->hasColumn('lesson_note_delivery_intents', 'note_version'));

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => $connection,
                '--path' => [
                    'database/migrations/2026_08_13_130000_create_lesson_note_attachments.php',
                    'database/migrations/2026_08_13_120000_create_attendance_and_notes.php',
                ],
                '--force' => true,
            ]));
            $this->assertFalse($schema->hasTable('attendance_records'));
            $this->assertTrue($schema->hasTable('event_occurrences'));

            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
            $this->assertTrue($schema->hasTable('lesson_note_delivery_previews'));
            $this->assertTrue($schema->hasTable('attendance_domain_commands'));
        } finally {
            DB::purge($connection);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
