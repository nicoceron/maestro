<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchedulingFoundationMigrationTest extends TestCase
{
    public function test_scheduling_foundation_rolls_back_and_remigrates_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'maestro-scheduling-');
        $this->assertIsString($path);
        $connection = 'scheduling_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]));
            $schema = DB::connection($connection)->getSchemaBuilder();
            $this->assertTrue($schema->hasTable('program_offering_overrides'));
            $this->assertTrue($schema->hasColumn('staff_availability_windows', 'enforcement'));
            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => $connection, '--path' => 'database/migrations/2026_08_13_100000_create_scheduling_foundation.php', '--force' => true,
            ]));
            $this->assertFalse($schema->hasTable('services'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
            $this->assertTrue($schema->hasTable('staff_travel_buffers'));
        } finally {
            DB::purge($connection);

            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
