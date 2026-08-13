<?php

namespace Tests\Feature\Security;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmPortableRefMap;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CrmDataPortabilityPostgresTest extends TestCase
{
    public function test_forced_rls_is_default_deny_and_cross_tenant_refs_are_hidden(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL required.');
        }
        $user = User::factory()->create();
        $first = Studio::factory()->create();
        $second = Studio::factory()->create();
        $ids = [];
        foreach ([$first, $second] as $studio) {
            DB::statement("select set_config('app.current_studio_id',?,false)", [$studio->getKey()]);
            $batch = CrmImportBatch::query()->create(['studio_id' => $studio->getKey(), 'requested_by_id' => $user->getKey(), 'idempotency_key' => 'pg-'.Str::random(12), 'request_fingerprint' => str_repeat('a', 64), 'original_name' => 'people.csv', 'quarantine_path' => 'q/'.Str::uuid().'.csv', 'source_sha256' => str_repeat('b', 64), 'source_size' => 100, 'expires_at' => now()->addHour()]);
            CrmPortableRefMap::query()->create(['studio_id' => $studio->getKey(), 'import_batch_id' => $batch->getKey(), 'source_type' => 'person', 'source_ref' => 'person-000001', 'target_id' => (string) Str::ulid()]);
            $ids[] = $batch->getKey();
        }
        [$runtime,$name] = $this->runtime();
        try {
            $this->assertSame(0, $runtime->table('crm_import_batches')->count());
            $runtime->statement("select set_config('app.current_studio_id',?,false)", [$first->getKey()]);
            $this->assertSame(1, $runtime->table('crm_import_batches')->count());
            $this->assertSame(1, $runtime->table('crm_portable_ref_maps')->count());
            $this->assertSame(0, $runtime->table('crm_import_batches')->where('studio_id', $second->getKey())->count());
            $denied = false;
            try {
                $runtime->table('crm_portable_ref_maps')->insert(['id' => (string) Str::ulid(), 'studio_id' => $second->getKey(), 'import_batch_id' => $ids[1], 'source_type' => 'person', 'source_ref' => 'cross', 'target_id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
            } catch (QueryException) {
                $denied = true;
            }$this->assertTrue($denied);
        } finally {
            DB::purge($name);
        }
    }

    private function runtime(): array
    {
        $name = 'crm_runtime_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$name}" => array_replace(config('database.connections.pgsql'), ['username' => 'maestro_runtime', 'password' => 'maestro_runtime'])]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }
}
