<?php

namespace Tests\Feature\DataPortability;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CrmDataPortabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_row_is_immutable_but_allows_safe_pii_redaction(): void
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        $commandKey = 'stage-'.Str::lower((string) Str::ulid());
        $batch = CrmImportBatch::query()->create(['studio_id' => $studio->getKey(), 'requested_by_id' => $user->getKey(), 'idempotency_key' => $commandKey, 'request_fingerprint' => str_repeat('a', 64), 'original_name' => 'people.csv', 'quarantine_path' => 'q/file.csv', 'source_sha256' => str_repeat('b', 64), 'source_size' => 100, 'row_count' => 1, 'expires_at' => now()->addHour()]);
        $row = CrmImportRow::query()->create(['studio_id' => $studio->getKey(), 'import_batch_id' => $batch->getKey(), 'row_number' => 2, 'content_hash' => str_repeat('c', 64), 'normalized_payload' => ['first_name' => 'Ada'], 'status' => 'created', 'decision' => 'create', 'result_digest' => str_repeat('d', 64), 'processed_at' => now()]);
        $this->expectException(\Throwable::class);
        $row->update(['row_number' => 3]);
    }

    public function test_requester_only_policy_denies_other_authorized_office_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $studio = Studio::factory()->create();
        $studio->members()->attach($owner, ['id' => (string) Str::ulid(), 'role' => 'owner', 'status' => 'active']);
        $studio->members()->attach($other, ['id' => (string) Str::ulid(), 'role' => 'office', 'status' => 'active']);
        $commandKey = 'stage-'.Str::lower((string) Str::ulid());
        $batch = CrmImportBatch::query()->create(['studio_id' => $studio->getKey(), 'requested_by_id' => $owner->getKey(), 'idempotency_key' => $commandKey, 'request_fingerprint' => str_repeat('a', 64), 'original_name' => 'people.csv', 'quarantine_path' => 'q/file.csv', 'source_sha256' => str_repeat('b', 64), 'source_size' => 100, 'expires_at' => now()->addHour()]);
        $this->assertTrue($owner->can('view', $batch));
        $this->assertFalse($other->can('view', $batch));
    }
}
