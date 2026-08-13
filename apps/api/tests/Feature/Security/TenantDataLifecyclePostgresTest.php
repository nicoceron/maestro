<?php

namespace Tests\Feature\Security;

use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\Models\Studio;
use App\TenantData\Jobs\BuildTenantDataExport;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TenantDataLifecyclePostgresTest extends TestCase
{
    public function test_failed_export_job_always_clears_its_session_tenant_context(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the tenant data lifecycle worker-context test.');
        }

        $studio = Studio::factory()->create();
        $job = new BuildTenantDataExport((string) Str::ulid(), (string) $studio->getKey());
        $job->failed(new RuntimeException('deterministic worker failure'));

        $this->assertSame(
            '',
            (string) (DB::scalar("select current_setting('app.current_studio_id', true)") ?? ''),
        );
    }

    public function test_restricted_runtime_role_is_default_deny_tenant_scoped_and_cannot_mutate_immutable_history(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the tenant data lifecycle RLS test.');
        }

        $first = Studio::factory()->create();
        $second = Studio::factory()->create();
        foreach ([$first, $second] as $studio) {
            DB::statement("select set_config('app.current_studio_id', ?, false)", [$studio->getKey()]);
            TenantRetentionPolicy::query()->create([
                'studio_id' => $studio->getKey(),
                'export_ttl_hours' => 24,
                'deletion_cooling_off_days' => 14,
                'deletion_quarantine_days' => 30,
                'operational_retention_days' => 2555,
                'media_retention_days' => 2555,
                'audit_retention_days' => 2555,
            ]);
        }
        DB::statement("select set_config('app.current_studio_id', ?, false)", [$first->getKey()]);
        DB::table('tenant_data_lifecycle_events')->insert([
            'id' => (string) Str::ulid(),
            'studio_id' => $first->getKey(),
            'aggregate_type' => 'retention_policy',
            'aggregate_id' => TenantRetentionPolicy::query()->where('studio_id', $first->getKey())->value('id'),
            'sequence' => 1,
            'event_type' => 'tenant.retention.created',
            'metadata' => '{}',
            'occurred_at' => now(),
        ]);

        [$runtime, $name] = $this->runtimeConnection();
        try {
            $this->assertSame(0, $runtime->table('tenant_retention_policies')->count());
            $this->setContext($runtime, (string) $first->getKey());
            $this->assertSame(1, $runtime->table('tenant_retention_policies')->count());
            $this->assertDatabaseHas('tenant_data_lifecycle_events', [
                'studio_id' => $first->getKey(),
                'aggregate_type' => 'retention_policy',
                'event_type' => 'tenant.retention.created',
            ]);

            $affected = $runtime->table('tenant_retention_policies')->where('studio_id', $second->getKey())->update([
                'export_ttl_hours' => 48,
            ]);
            $this->assertSame(0, $affected);
            $this->assertSame(0, $runtime->table('tenant_retention_policies')->where('studio_id', $second->getKey())->count());

            $historyMutationDenied = false;
            try {
                DB::table('tenant_data_lifecycle_events')
                    ->where('studio_id', $first->getKey())
                    ->where('aggregate_type', 'retention_policy')
                    ->update(['event_type' => 'rewritten']);
            } catch (QueryException) {
                $historyMutationDenied = true;
            }
            $this->assertTrue($historyMutationDenied);
        } finally {
            DB::purge($name);
        }
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }

        $name = 'pgsql_runtime_tenant_data_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$name}" => array_replace(
            config('database.connections.pgsql'),
            ['username' => $username, 'password' => $password],
        )]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }

    private function setContext(ConnectionInterface $connection, string $studioId): void
    {
        $connection->statement("select set_config('app.current_studio_id', ?, false)", [$studioId]);
    }
}
