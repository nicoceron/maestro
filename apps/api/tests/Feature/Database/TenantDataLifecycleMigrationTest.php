<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TenantDataLifecycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_data_lifecycle_schema_rolls_back_and_remigrates_cleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'maestro-tenant-data-');
        $this->assertIsString($path);
        $connection = 'tenant_data_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]));
            $schema = DB::connection($connection)->getSchemaBuilder();
            foreach (['tenant_data_exports', 'tenant_retention_policies', 'tenant_deletion_requests', 'tenant_restore_drills', 'tenant_data_lifecycle_events'] as $table) {
                $this->assertTrue($schema->hasTable($table));
            }
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => $connection, '--step' => 1, '--force' => true]));
            $this->assertFalse($schema->hasTable('tenant_data_exports'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
            $this->assertTrue($schema->hasTable('tenant_data_exports'));
        } finally {
            DB::purge($connection);
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
