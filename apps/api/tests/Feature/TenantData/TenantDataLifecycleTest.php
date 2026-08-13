<?php

namespace Tests\Feature\TenantData;

use App\DataLifecycle\Actions\AdvanceTenantDeletionLifecycle;
use App\DataLifecycle\Actions\ApproveTenantDeletion;
use App\DataLifecycle\Actions\RequestTenantDeletion;
use App\DataLifecycle\Actions\RequestTenantRestoreDrill;
use App\DataLifecycle\Actions\UpdateTenantRetentionPolicy;
use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Enums\TenantRestoreDrillStatus;
use App\DataLifecycle\Jobs\RunTenantRestoreDrill;
use App\DataLifecycle\Models\TenantDataLifecycleEvent;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\Enums\StudioStatus;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use App\TenantData\Actions\ExpireTenantDataExports;
use App\TenantData\Actions\RequestTenantDataExport;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Jobs\BuildTenantDataExport;
use App\TenantData\Models\TenantDataExport;
use App\TenantData\Support\TenantExportBuilder;
use App\TenantData\Support\TenantExportCatalog;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use LogicException;
use Tests\TestCase;

final class TenantDataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            // Browser-session registry behavior has dedicated production-driver
            // coverage; these feature tests isolate tenant lifecycle policy.
            'session.driver' => 'array',
        ]);
        Storage::fake('tenant_exports');
        Queue::fake();
        $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    }

    public function test_owner_export_is_idempotent_deterministic_secret_free_encrypted_and_verifiable(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $secret = 'do-not-export-'.str_repeat('x', 24);
        $studio->forceFill(['settings' => ['internal_bearer' => $secret]])->save();
        $invitationId = (string) Str::ulid();
        StudioInvitation::query()->create([
            'id' => $invitationId,
            'studio_id' => $studio->getKey(),
            'lineage_id' => $invitationId,
            'email_normalized' => 'future@example.test',
            'role' => 'teacher',
            'token_hash' => hash('sha256', $secret),
            'invited_by_id' => $owner->getKey(),
            'expires_at' => now()->addDay(),
        ]);

        $action = app(RequestTenantDataExport::class);
        $export = $action->handle($studio, $owner, 'export-once', false);
        $replayed = $action->handle($studio, $owner, 'export-once', false);
        $this->assertTrue($export->is($replayed));
        $this->expectDomainCode(
            fn () => $action->handle($studio, $owner, 'export-once', true),
            'IDEMPOTENCY_KEY_REUSED',
        );
        Queue::assertPushed(BuildTenantDataExport::class, 1);

        $this->runExport($export);
        $export->refresh();
        $this->assertSame(TenantDataExportStatus::Ready, $export->status);
        $this->assertNotNull($export->archive_ciphertext_sha256);
        $this->assertSame('maestro-tenant-portable-archive', $export->manifest['format']);
        $this->assertArrayHasKey('snapshot_boundary', $export->manifest);
        $this->assertNotEmpty($export->manifest['datasets']);

        $ciphertext = Storage::disk('tenant_exports')->get($export->archive_path);
        $this->assertStringStartsWith("MAESTRO-TENANT-EXPORT\0", $ciphertext);
        $this->assertStringNotContainsString($secret, $ciphertext);
        $verified = app(TenantExportBuilder::class)->readAndVerify($export);
        $this->assertSame($export->getKey(), $verified['manifest']['export_id']);
        $this->assertArrayHasKey('studios', $verified['datasets']);
        $this->assertStringNotContainsString($secret, json_encode($verified, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('token_hash', $verified['datasets']['studio_invitations']);

        $attachmentColumns = app(TenantExportCatalog::class)->exportableColumns(
            $export->getConnection(),
            'lesson_note_attachments',
        );
        $this->assertNotContains('quarantine_disk', $attachmentColumns);
        $this->assertNotContains('quarantine_key', $attachmentColumns);
    }

    public function test_export_authorization_recent_confirmation_mfa_and_one_use_download_are_enforced(): void
    {
        [$owner, $studio] = $this->ownerFixture(mfa: true);
        $teacher = User::factory()->create([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ]);
        StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey()]);

        $teacherResponse = $this->withSession($this->recentSession())
            ->actingAs($teacher)
            ->postJson("/api/v1/studios/{$studio->slug}/data-exports", [], ['Idempotency-Key' => 'teacher']);
        $this->assertSame(403, $teacherResponse->status(), $teacherResponse->getContent());

        $this->flushSession();
        $this->actingAs($owner)
            ->postJson("/api/v1/studios/{$studio->slug}/data-exports", [], ['Idempotency-Key' => 'missing-confirmation'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'RECENT_CONFIRMATION_REQUIRED');

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson("/api/v1/studios/{$studio->slug}/data-exports", [], ['Idempotency-Key' => 'missing-mfa'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'MFA_REQUIRED');

        $response = $this->withSession($this->recentSession())
            ->postJson("/api/v1/studios/{$studio->slug}/data-exports", [], ['Idempotency-Key' => 'api-export'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonMissingPath('data.archive_sha256')
            ->assertJsonMissingPath('data.archive_ciphertext_sha256');
        $export = TenantDataExport::query()->findOrFail($response->json('data.id'));
        $this->runExport($export);
        $url = $this->withSession($this->recentSession())
            ->postJson("/api/v1/studios/{$studio->slug}/data-exports/{$export->getKey()}/download-url")
            ->assertOk()
            ->json('data.url');

        $this->withSession($this->recentSession())->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.maestro.tenant-export+json');
        $this->withSession($this->recentSession())->get($url)->assertGone();
    }

    public function test_restore_drill_checks_archive_schema_counts_checksums_and_tenant_invariants_without_writes(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $export = app(RequestTenantDataExport::class)->handle($studio, $owner, 'restore-source', true);
        $this->runExport($export);
        $export->refresh();

        $drill = app(RequestTenantRestoreDrill::class)->handle($export, $owner);
        $job = new RunTenantRestoreDrill($drill->getKey(), (string) $studio->getKey());
        app()->call([$job, 'handle']);
        $drill->refresh();

        $this->assertSame(TenantRestoreDrillStatus::Verified, $drill->status);
        $this->assertTrue($drill->verification['archive_checksum_valid']);
        $this->assertTrue($drill->verification['tenant_invariants_valid']);
        $this->assertTrue($drill->verification['dry_run']);
        $this->assertSame($studio->getKey(), Studio::query()->findOrFail($studio->getKey())->getKey());
    }

    public function test_deletion_requires_export_second_owner_and_cooling_off_then_reversibly_suspends_quarantines_and_restores(): void
    {
        [$requester, $studio] = $this->ownerFixture(mfa: true);
        $approver = User::factory()->create();
        StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $approver->getKey(),
            'role' => 'administrator',
        ]);
        $teacher = User::factory()->create();
        $teacherMembership = StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $teacher->getKey()]);

        $deletion = app(RequestTenantDeletion::class)->handle(
            $studio,
            $requester,
            'This studio is being closed after an owner review.',
            "DELETE {$studio->slug}",
            'delete-once',
        );
        $this->assertSame(TenantDeletionStatus::CoolingOff, $deletion->status);
        $this->runExport($deletion->export);

        $this->expectDomainCode(
            fn () => app(ApproveTenantDeletion::class)->handle($deletion, $requester),
            'COOLING_OFF_ACTIVE',
        );
        $this->travelTo($deletion->cooling_off_ends_at->addSecond());
        $this->expectDomainCode(
            fn () => app(ApproveTenantDeletion::class)->handle($deletion, $requester),
            'SECOND_OWNER_APPROVAL_REQUIRED',
        );

        $export = $deletion->export()->firstOrFail();
        $this->assertTrue($export->expires_at->isFuture());
        $archiveExpiresAt = $export->expires_at;
        $export->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->expectDomainCode(
            fn () => app(ApproveTenantDeletion::class)->handle($deletion, $approver),
            'VERIFIED_EXPORT_REQUIRED',
        );
        $export->forceFill(['expires_at' => $archiveExpiresAt])->save();
        $archivePath = (string) $export->archive_path;
        $archive = Storage::disk('tenant_exports')->get($archivePath);
        Storage::disk('tenant_exports')->delete($archivePath);
        $this->expectDomainCode(
            fn () => app(ApproveTenantDeletion::class)->handle($deletion, $approver),
            'VERIFIED_EXPORT_REQUIRED',
        );
        Storage::disk('tenant_exports')->put($archivePath, $archive.'tampered');
        $this->expectDomainCode(
            fn () => app(ApproveTenantDeletion::class)->handle($deletion, $approver),
            'VERIFIED_EXPORT_REQUIRED',
        );
        Storage::disk('tenant_exports')->put($archivePath, $archive);

        $deletion = app(ApproveTenantDeletion::class)->handle($deletion, $approver);
        $deletion = app(AdvanceTenantDeletionLifecycle::class)->handle($deletion);
        $this->assertSame(TenantDeletionStatus::Suspended, $deletion->status);
        $this->assertSame(StudioStatus::Suspended, $studio->fresh()->status);
        $this->assertSame('suspended', $teacherMembership->fresh()->status->value);
        $this->actingAs($requester)
            ->getJson("/api/v1/studios/{$studio->slug}/people")
            ->assertForbidden();
        $this->getJson("/api/v1/studios/{$studio->slug}/deletion-requests/{$deletion->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $deletion = app(AdvanceTenantDeletionLifecycle::class)->handle($deletion);
        $this->assertSame(TenantDeletionStatus::Quarantined, $deletion->status);
        $this->travelTo($deletion->purge_eligible_at->addSecond());
        $deletion = app(AdvanceTenantDeletionLifecycle::class)->handle($deletion);
        $this->assertSame(TenantDeletionStatus::PurgeEligible, $deletion->status);
        $this->assertDatabaseHas('studios', ['id' => $studio->getKey()]);

        $this->withSession($this->recentSession())
            ->actingAs($requester)
            ->postJson("/api/v1/studios/{$studio->slug}/deletion-requests/{$deletion->getKey()}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'restored');
        $restored = $deletion->fresh();
        $this->assertSame(TenantDeletionStatus::Restored, $restored->status);
        $this->assertSame(StudioStatus::Active, $studio->fresh()->status);
        $this->assertSame('active', $teacherMembership->fresh()->status->value);
        $this->assertSame([
            'tenant.deletion.cooling_off_started',
            'tenant.deletion.approved',
            'tenant.deletion.suspended',
            'tenant.deletion.quarantined',
            'tenant.deletion.purge_eligible',
            'tenant.deletion.restoring',
            'tenant.deletion.restored',
        ], TenantDataLifecycleEvent::query()
            ->where('aggregate_type', 'tenant_deletion')
            ->where('aggregate_id', $deletion->getKey())
            ->orderBy('sequence')->pluck('event_type')->all());
    }

    public function test_legal_hold_blocks_deletion_and_audit_history_is_immutable(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $policy = TenantRetentionPolicy::query()->create([
            'studio_id' => $studio->getKey(),
            'export_ttl_hours' => 24,
            'deletion_cooling_off_days' => 14,
            'deletion_quarantine_days' => 30,
            'operational_retention_days' => 2555,
            'media_retention_days' => 2555,
            'audit_retention_days' => 2555,
        ]);
        app(UpdateTenantRetentionPolicy::class)->placeLegalHold($policy, $owner, 'Preserve records for the active legal review.');

        $export = app(RequestTenantDataExport::class)->handle($studio, $owner, 'held-export', false);
        $this->runExport($export);
        $export->forceFill(['expires_at' => now()->subMinute()])->save();
        $archivePath = (string) $export->archive_path;
        $this->assertSame(0, app(ExpireTenantDataExports::class)->handle());
        $this->assertSame(TenantDataExportStatus::Ready, $export->refresh()->status);
        Storage::disk('tenant_exports')->assertExists($archivePath);
        $this->expectDomainCode(
            fn () => app(RequestTenantDeletion::class)->handle(
                $studio,
                $owner,
                'Deletion should be blocked by the hold.',
                "DELETE {$studio->slug}",
                'blocked-delete',
            ),
            'LEGAL_HOLD_ACTIVE',
        );

        $event = TenantDataLifecycleEvent::query()->firstOrFail();
        $this->expectException(LogicException::class);
        $event->forceFill(['event_type' => 'rewritten'])->save();
    }

    private function runExport(TenantDataExport $export): void
    {
        $job = new BuildTenantDataExport($export->getKey(), (string) $export->studio_id);
        app()->call([$job, 'handle']);
    }

    /** @return array{User, Studio} */
    private function ownerFixture(bool $mfa = false): array
    {
        $user = User::factory()->create($mfa ? [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ] : []);
        $studio = Studio::factory()->create(['timezone' => 'America/Bogota']);
        StudioMembership::factory()->owner()->create(['studio_id' => $studio->getKey(), 'user_id' => $user->getKey()]);
        app(RequestDatabaseContext::class)->activateStudioIdForSession((string) $studio->getKey());

        return [$user, $studio];
    }

    /** @return array<string, int> */
    private function recentSession(): array
    {
        return [
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_verified_at' => now()->timestamp,
        ];
    }

    private function expectDomainCode(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail("Expected domain error {$code}.");
        } catch (DomainException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
    }
}
