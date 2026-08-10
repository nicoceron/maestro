<?php

namespace Tests\Feature\Security;

use App\Models\Household;
use App\Models\Studio;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostgresRowLevelSecurityTest extends TestCase
{
    public function test_restricted_runtime_role_is_default_deny_and_cannot_cross_studios(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        $runtimeUsername = (string) env('DB_RUNTIME_USERNAME');
        $runtimePassword = (string) env('DB_RUNTIME_PASSWORD');

        if ($runtimeUsername === '' || $runtimePassword === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }

        $firstStudio = Studio::factory()->create();
        $secondStudio = Studio::factory()->create();
        $firstHousehold = Household::factory()->for($firstStudio)->create();
        Household::factory()->for($secondStudio)->create();
        $runtimeConnectionName = 'pgsql_runtime_test';
        $baseConnection = config('database.connections.pgsql');

        config([
            "database.connections.{$runtimeConnectionName}" => array_replace(
                $baseConnection,
                [
                    'username' => $runtimeUsername,
                    'password' => $runtimePassword,
                ],
            ),
        ]);
        DB::purge($runtimeConnectionName);
        $runtime = DB::connection($runtimeConnectionName);

        try {
            $this->assertRestrictedRole($runtime, $runtimeUsername);
            $this->assertSame(0, $runtime->table('households')->count());

            $runtime->statement(
                "select set_config('app.current_studio_id', ?, false)",
                [$firstStudio->getKey()],
            );

            $this->assertSame(
                [$firstHousehold->getKey()],
                $runtime->table('households')->pluck('id')->all(),
            );

            $allowedId = (string) Str::ulid();
            $runtime->table('households')->insert([
                'id' => $allowedId,
                'studio_id' => $firstStudio->getKey(),
                'name' => 'RLS allowed household',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertSame(2, $runtime->table('households')->count());

            $crossStudioWriteWasDenied = false;

            try {
                $runtime->table('households')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $secondStudio->getKey(),
                    'name' => 'RLS rejected household',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossStudioWriteWasDenied = true;
            }

            $this->assertTrue($crossStudioWriteWasDenied);
        } finally {
            DB::purge($runtimeConnectionName);
            $firstStudio->forceDelete();
            $secondStudio->forceDelete();
        }
    }

    private function assertRestrictedRole(ConnectionInterface $connection, string $role): void
    {
        $attributes = $connection->table('pg_roles')
            ->where('rolname', $role)
            ->first(['rolsuper', 'rolbypassrls']);

        $this->assertNotNull($attributes);
        $this->assertFalse($attributes->rolsuper);
        $this->assertFalse($attributes->rolbypassrls);
    }
}
