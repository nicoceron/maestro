<?php

namespace Tests\Feature\DataPortability;

use App\DataPortability\Jobs\BuildCrmPortableExportJob;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Support\CrmPortableCsv;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

final class CrmDataPortabilityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'session.driver' => 'array']);
        Storage::fake('crm_data_portability');
        Queue::fake();
        $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    }

    public function test_owner_can_stage_preview_clean_create_without_domain_writes_and_fetch_bounded_plan(): void
    {
        [$owner,$studio] = $this->ownerFixture();
        $template = $this->actingAs($owner)->get("/api/v1/studios/{$studio->slug}/data-portability/template")
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8; header=present')->getContent();

        $response = $this->withSession($this->recentSession())->post("/api/v1/studios/{$studio->slug}/data-portability/imports", [
            'file' => UploadedFile::fake()->createWithContent('people.csv', $template),
        ], ['Idempotency-Key' => 'stage-api-0001'])->assertAccepted()->assertJsonPath('data.status', 'staged');
        $id = $response->json('data.id');
        $version = (int) $response->json('data.version');
        $this->assertSame(1, $version, $response->getContent());
        $this->assertDatabaseCount('people', 0);

        $preview = $this->postJson("/api/v1/studios/{$studio->slug}/data-portability/imports/{$id}/preview", ['import_version' => $version])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.summary.conflicts', 0)
            ->assertJsonPath('rows.data.0.status', 'resolved')->assertJsonPath('rows.data.0.decision', 'create');
        $this->assertDatabaseCount('people', 0);
        $this->getJson("/api/v1/studios/{$studio->slug}/data-portability/imports/{$id}/rows?per_page=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.0.resolution_token');
        $this->assertGreaterThan($version, $preview->json('data.version'));
        $this->postJson("/api/v1/studios/{$studio->slug}/data-portability/imports/{$id}/preview", ['import_version' => $version])
            ->assertConflict()->assertJsonPath('code', 'CRM_IMPORT_VERSION_CONFLICT');
    }

    public function test_stage_requires_step_up_and_teacher_is_denied(): void
    {
        [$owner,$studio] = $this->ownerFixture();
        $file = UploadedFile::fake()->createWithContent('people.csv', app(CrmPortableCsv::class)->template());
        $this->actingAs($owner)->post("/api/v1/studios/{$studio->slug}/data-portability/imports", ['file' => $file], ['Idempotency-Key' => 'stage-api-0002'])
            ->assertStatus(423)->assertJsonPath('code', 'RECENT_CONFIRMATION_REQUIRED');

        $teacher = User::factory()->create(['two_factor_secret' => $this->mfaSecret(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'), 'two_factor_confirmed_at' => now()]);
        StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey(), 'role' => 'teacher', 'status' => 'active']);
        $this->actingAs($teacher)->withSession($this->recentSession())->post("/api/v1/studios/{$studio->slug}/data-portability/imports", [
            'file' => UploadedFile::fake()->createWithContent('people.csv', app(CrmPortableCsv::class)->template()),
        ], ['Idempotency-Key' => 'stage-api-0003'])->assertForbidden();
    }

    public function test_artifacts_are_requester_only_and_export_request_is_queued(): void
    {
        [$owner,$studio] = $this->ownerFixture();
        $other = User::factory()->create(['two_factor_secret' => $this->mfaSecret(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'), 'two_factor_confirmed_at' => now()]);
        StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $other->getKey(), 'role' => 'office', 'status' => 'active']);
        $batch = CrmImportBatch::query()->create(['studio_id' => $studio->getKey(), 'requested_by_id' => $owner->getKey(), 'idempotency_key' => 'stage-api-0004', 'request_fingerprint' => str_repeat('a', 64), 'original_name' => 'people.csv', 'quarantine_path' => 'q/file.csv', 'source_sha256' => str_repeat('b', 64), 'source_size' => 100, 'expires_at' => now()->addHour()]);
        $this->actingAs($other)->getJson("/api/v1/studios/{$studio->slug}/data-portability/imports/{$batch->getKey()}")->assertNotFound();

        $response = $this->actingAs($owner)->withSession($this->recentSession())->postJson("/api/v1/studios/{$studio->slug}/data-portability/exports", [], ['Idempotency-Key' => 'export-api-001'])
            ->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonMissingPath('data.archive_sha256');
        Queue::assertPushed(BuildCrmPortableExportJob::class, fn ($job) => $job->exportId === $response->json('data.id'));
    }

    public function test_stage_idempotency_reuse_with_different_content_is_typed_conflict(): void
    {
        [$owner,$studio] = $this->ownerFixture();
        $session = $this->recentSession();
        $this->actingAs($owner)->withSession($session)->post("/api/v1/studios/{$studio->slug}/data-portability/imports", ['file' => UploadedFile::fake()->createWithContent('people.csv', app(CrmPortableCsv::class)->template())], ['Idempotency-Key' => 'stage-reuse-001'])->assertAccepted();
        $changed = str_replace('Ada', 'Different', app(CrmPortableCsv::class)->template());
        $this->withSession($session)->post("/api/v1/studios/{$studio->slug}/data-portability/imports", ['file' => UploadedFile::fake()->createWithContent('people.csv', $changed)], ['Idempotency-Key' => 'stage-reuse-001'])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    private function ownerFixture(): array
    {
        $owner = User::factory()->create(['two_factor_secret' => $this->mfaSecret(), 'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'), 'two_factor_confirmed_at' => now()]);
        $studio = Studio::factory()->create();
        StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner', 'status' => 'active']);

        return [$owner, $studio];
    }

    private function recentSession(): array
    {
        return ['auth.password_confirmed_at' => now()->timestamp, 'auth.mfa_verified_at' => now()->timestamp];
    }

    private function mfaSecret(): string
    {
        return Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP');
    }
}
