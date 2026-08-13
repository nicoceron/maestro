<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PeopleLifecycleMigrationTest extends TestCase
{
    public function test_people_and_invitation_lifecycle_migrations_rollback_and_remigrate_without_losing_token_not_null_fidelity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'maestro-migration-');
        $this->assertIsString($path);
        $connection = 'migration_'.Str::lower((string) Str::ulid());
        config([
            "database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--database' => $connection,
                '--force' => true,
            ]));
            $database = DB::connection($connection);
            $studioId = (string) Str::ulid();
            $invitationId = (string) Str::ulid();
            $database->table('studios')->insert([
                'id' => $studioId,
                'name' => 'Migration Studio',
                'slug' => 'migration-studio',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $database->table('studio_invitations')->insert([
                'id' => $invitationId,
                'studio_id' => $studioId,
                'lineage_id' => $invitationId,
                'delivery_version' => 1,
                'email_normalized' => 'retired@example.test',
                'role' => 'teacher',
                'token_hash' => null,
                'expires_at' => now()->subDay(),
                'revoked_at' => now()->subHour(),
                'send_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ([
                'database/migrations/2026_08_13_110000_create_event_scheduling_core.php',
                'database/migrations/2026_08_13_100000_create_scheduling_foundation.php',
                'database/migrations/2026_08_12_100000_expand_people_and_student_lifecycle.php',
                'database/migrations/2026_08_10_094000_add_invitation_delivery_and_audit_foundation.php',
            ] as $migration) {
                $this->assertSame(0, Artisan::call('migrate:rollback', [
                    '--database' => $connection,
                    '--path' => $migration,
                    '--force' => true,
                ]));
            }
            $tokenColumn = collect($database->select('pragma table_info(studio_invitations)'))
                ->firstWhere('name', 'token_hash');
            $this->assertNotNull($tokenColumn);
            $this->assertSame(1, $tokenColumn->notnull);
            $this->assertSame(
                hash('sha256', "maestro-redacted-invitation\0{$invitationId}"),
                $database->table('studio_invitations')->where('id', $invitationId)->value('token_hash'),
            );

            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => $connection,
                '--force' => true,
            ]));
            $this->assertTrue($database->getSchemaBuilder()->hasTable('student_status_transitions'));
        } finally {
            DB::purge($connection);

            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
