<?php

namespace Tests\Feature\Filament;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Models\CrmPortableExport;
use App\Enums\MembershipRole;
use App\Filament\DataPortability\CrmDataPortabilityGateway;
use App\Filament\DataPortability\CrmExportWorkspace;
use App\Filament\DataPortability\CrmImportWorkspace;
use App\Filament\DataPortability\LaravelCrmDataPortabilityGateway;
use App\Filament\Pages\CrmDataPortability;
use App\Models\Household;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

final class CrmDataPortabilityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    }

    public function test_portability_access_is_an_explicit_active_membership_capability(): void
    {
        $studio = Studio::factory()->create();

        foreach ([MembershipRole::Owner, MembershipRole::Administrator, MembershipRole::Office] as $role) {
            [$user, $membership] = $this->membership($studio, $role);
            $this->filamentAs($user, $studio, $membership);
            $this->assertTrue(CrmDataPortability::canAccess(), $role->value);
        }

        foreach ([MembershipRole::Billing, MembershipRole::Teacher] as $role) {
            [$user, $membership] = $this->membership($studio, $role);
            $this->filamentAs($user, $studio, $membership);
            $this->assertFalse(CrmDataPortability::canAccess(), $role->value);
        }
    }

    public function test_container_binds_the_filament_adapter_to_the_domain_gateway(): void
    {
        $this->assertInstanceOf(
            LaravelCrmDataPortabilityGateway::class,
            $this->app->make(CrmDataPortabilityGateway::class),
        );
    }

    public function test_live_adapter_projects_bounded_versioned_duplicate_plan_and_signed_download(): void
    {
        $studio = Studio::factory()->create();
        [$owner, $membership] = $this->membership($studio, MembershipRole::Owner);
        $this->filamentAs($owner, $studio, $membership);
        $candidate = Person::factory()->for($studio)->create(['first_name' => 'Morgan', 'version' => 4]);
        $household = Household::factory()->for($studio)->create(['name' => 'Morgan Household', 'version' => 3]);
        $batch = CrmImportBatch::query()->create([
            'studio_id' => $studio->getKey(),
            'requested_by_id' => $owner->getKey(),
            'idempotency_key' => 'filament-live-import',
            'request_fingerprint' => hash('sha256', 'filament-live-import'),
            'status' => 'needs_resolution',
            'original_name' => 'people.csv',
            'quarantine_path' => 'quarantine/safe.csv',
            'source_sha256' => hash('sha256', 'safe'),
            'source_size' => 4,
            'row_count' => 1,
            'conflicted_rows' => 1,
            'column_mapping' => ['first_name' => 'First name'],
            'expires_at' => now()->addDay(),
        ]);
        CrmImportRow::query()->create([
            'studio_id' => $studio->getKey(),
            'import_batch_id' => $batch->getKey(),
            'row_number' => 2,
            'plan_version' => 7,
            'status' => 'conflict',
            'decision' => 'conflict',
            'match_kind' => 'ambiguous_email',
            'normalized_payload' => ['first_name' => 'Morgan', 'email' => 'morgan@example.test'],
            'match_candidates' => ['people' => [[
                'id' => $candidate->getKey(),
                'version' => 4,
                'display_name' => 'Morgan Candidate',
            ]], 'households' => [[
                'id' => $household->getKey(),
                'version' => 3,
                'name' => 'Morgan Household',
            ]]],
        ]);
        $export = CrmPortableExport::query()->create([
            'studio_id' => $studio->getKey(),
            'requested_by_id' => $owner->getKey(),
            'idempotency_key' => 'filament-live-export',
            'request_fingerprint' => hash('sha256', 'filament-live-export'),
            'status' => 'ready',
            'manifest' => ['datasets' => [['row_count' => 1]]],
            'archive_path' => 'exports/safe.maestro',
            'archive_sha256' => hash('sha256', 'safe'),
            'archive_size' => 4,
            'ready_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $gateway = $this->app->make(CrmDataPortabilityGateway::class);
        $workspace = $gateway->importWorkspace($studio, $owner, $batch->getKey());
        $resolved = $gateway->resolveDuplicates($studio, $owner, $batch->getKey(), 1, [
            $workspace->duplicates[0]['id'] => [
                'decision' => 'update',
                'candidate_id' => $candidate->getKey(),
                'household_candidate_id' => $household->getKey(),
            ],
        ]);
        $download = $gateway->exportWorkspace($studio, $owner, $export->getKey());

        $this->assertSame(7, $workspace->duplicates[0]['plan_version']);
        $this->assertSame($candidate->getKey(), $workspace->duplicates[0]['candidates'][0]['id']);
        $this->assertSame($household->getKey(), $workspace->duplicates[0]['household_candidates'][0]['id']);
        $this->assertSame('Morgan Household', $workspace->duplicates[0]['household_candidates'][0]['label']);
        $this->assertSame('ready', $resolved->status);
        $this->assertSame('resolved', CrmImportRow::query()->sole()->status);
        $this->assertSame($household->getKey(), CrmImportRow::query()->sole()->candidate_household_id);
        $this->assertStringContainsString('signature=', (string) $download->downloadUrl);
        $this->assertStringNotContainsString('exports/safe.maestro', (string) $download->downloadUrl);
    }

    public function test_page_renders_accessible_workflow_through_bound_adapter(): void
    {
        $studio = Studio::factory()->create();
        [$owner, $membership] = $this->membership($studio, MembershipRole::Owner);
        $this->filamentAs($owner, $studio, $membership);

        $this->app->instance(CrmDataPortabilityGateway::class, new FakeCrmDataPortabilityGateway);

        Livewire::test(CrmDataPortability::class)
            ->assertSuccessful()
            ->assertSee('CRM import progress')
            ->assertSee('Start with a clean template')
            ->assertSee('Private staging with tenant and actor reauthorization.')
            ->assertActionVisible('downloadTemplate')
            ->assertActionVisible('uploadCsv')
            ->assertActionVisible('requestExport')
            ->assertDontSee('/private/')
            ->assertDontSee('storage/');
    }

    public function test_mapping_and_duplicate_decisions_are_allowlisted_and_versioned(): void
    {
        $studio = Studio::factory()->create();
        [$owner, $membership] = $this->membership($studio, MembershipRole::Owner);
        $this->enableMfa($owner);
        $this->withSession($this->recentSession());
        $this->filamentAs($owner, $studio, $membership);
        $gateway = new FakeCrmDataPortabilityGateway;
        $this->app->instance(CrmDataPortabilityGateway::class, $gateway);
        $workspace = $gateway->stagedWorkspace();

        Livewire::test(CrmDataPortability::class)
            ->set('import', $workspace->toArray())
            ->call('setMapping', 'Email', 'email')
            ->call('previewImport')
            ->assertHasNoErrors()
            ->assertSee('Resolve possible duplicates')
            ->assertSee('Household to use')
            ->call('setDuplicateResolution', 'row-2', 'update')
            ->call('setDuplicateCandidate', 'row-2', '01JFAKEPERSON0000000000000')
            ->call('setDuplicateHouseholdCandidate', 'row-2', '01JFAKEHOUSEHOLD00000000000')
            ->call('saveDuplicateResolutions')
            ->assertHasNoErrors()
            ->assertSet('import.version', 3)
            ->assertSet('import.canCommit', true);

        $this->assertSame(1, $gateway->previewExpectedVersion);
        $this->assertSame(2, $gateway->resolveExpectedVersion);
        $this->assertSame(['row-2' => [
            'decision' => 'update',
            'candidate_id' => '01JFAKEPERSON0000000000000',
            'household_candidate_id' => '01JFAKEHOUSEHOLD00000000000',
        ]], $gateway->resolutions);
    }

    public function test_sensitive_duplicate_update_rejects_stale_step_up_session(): void
    {
        $studio = Studio::factory()->create();
        [$owner, $membership] = $this->membership($studio, MembershipRole::Owner);
        $this->enableMfa($owner);
        $this->withSession([
            'auth.password_confirmed_at' => now()->subSeconds(601)->timestamp,
            'auth.mfa_verified_at' => now()->timestamp,
        ]);
        $this->filamentAs($owner, $studio, $membership);
        $gateway = new FakeCrmDataPortabilityGateway;
        $this->app->instance(CrmDataPortabilityGateway::class, $gateway);

        Livewire::test(CrmDataPortability::class)
            ->set('import', $gateway->previewedWorkspace()->toArray())
            ->set('duplicateResolutions', ['row-2' => 'update'])
            ->set('duplicateCandidates', ['row-2' => '01JFAKEPERSON0000000000000'])
            ->set('duplicateHouseholdCandidates', ['row-2' => '01JFAKEHOUSEHOLD00000000000'])
            ->call('saveDuplicateResolutions')
            ->assertStatus(423);

        $this->assertNull($gateway->resolveExpectedVersion);
    }

    /** @return array{User, StudioMembership} */
    private function membership(Studio $studio, MembershipRole $role): array
    {
        $user = User::factory()->create();
        $membership = StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        return [$user, $membership];
    }

    private function filamentAs(User $user, Studio $studio, StudioMembership $membership): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        app(TenantContext::class)->activate($studio, $membership);
    }

    private function enableMfa(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    /** @return array<string, int> */
    private function recentSession(): array
    {
        return [
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_verified_at' => now()->timestamp,
        ];
    }
}

final class FakeCrmDataPortabilityGateway implements CrmDataPortabilityGateway
{
    public ?int $previewExpectedVersion = null;

    public ?int $resolveExpectedVersion = null;

    /** @var array<string, array{decision:string,candidate_id?:string,household_candidate_id?:string}> */
    public array $resolutions = [];

    public function templateDownloadUrl(Studio $studio, User $actor): string
    {
        return '/manage/studio/'.$studio->slug.'/crm-data-portability/template';
    }

    public function stageImport(Studio $studio, User $actor, TemporaryUploadedFile $file, string $idempotencyKey): CrmImportWorkspace
    {
        return $this->stagedWorkspace();
    }

    public function previewImport(Studio $studio, User $actor, string $importId, int $expectedVersion, array $mapping): CrmImportWorkspace
    {
        $this->previewExpectedVersion = $expectedVersion;

        return $this->previewedWorkspace();
    }

    public function resolveDuplicates(Studio $studio, User $actor, string $importId, int $expectedVersion, array $resolutions): CrmImportWorkspace
    {
        $this->resolveExpectedVersion = $expectedVersion;
        $this->resolutions = $resolutions;

        return $this->previewedWorkspace(version: 3, canCommit: true, resolution: 'update');
    }

    public function commitImport(Studio $studio, User $actor, string $importId, int $expectedVersion, string $idempotencyKey): CrmImportWorkspace
    {
        return $this->previewedWorkspace(version: $expectedVersion + 1, canCommit: false, resolution: 'update');
    }

    public function resumeImport(Studio $studio, User $actor, string $importId, int $expectedVersion, string $idempotencyKey): CrmImportWorkspace
    {
        return $this->commitImport($studio, $actor, $importId, $expectedVersion, $idempotencyKey);
    }

    public function importWorkspace(Studio $studio, User $actor, string $importId): CrmImportWorkspace
    {
        return $this->previewedWorkspace();
    }

    public function requestExport(Studio $studio, User $actor, string $idempotencyKey): CrmExportWorkspace
    {
        return new CrmExportWorkspace((string) Str::ulid(), 'queued');
    }

    public function exportWorkspace(Studio $studio, User $actor, string $exportId): CrmExportWorkspace
    {
        return new CrmExportWorkspace($exportId, 'ready', ['total' => 1, 'processed' => 1], '/signed/export');
    }

    public function stagedWorkspace(): CrmImportWorkspace
    {
        return new CrmImportWorkspace(
            id: (string) Str::ulid(),
            version: 1,
            status: 'staged',
            fileName: 'people.csv',
            sourceColumns: ['First name', 'Email'],
            mappingTargets: ['first_name' => 'First name', 'email' => 'Email'],
        );
    }

    public function previewedWorkspace(int $version = 2, bool $canCommit = false, ?string $resolution = null): CrmImportWorkspace
    {
        return new CrmImportWorkspace(
            id: (string) Str::ulid(),
            version: $version,
            status: $canCommit ? 'ready' : 'needs_resolution',
            fileName: 'people.csv',
            sourceColumns: ['First name', 'Email'],
            mappingTargets: ['first_name' => 'First name', 'email' => 'Email'],
            mapping: ['Email' => 'email'],
            summary: ['total' => 2, 'valid' => 2, 'invalid' => 0, 'duplicates' => 1, 'creates' => 1, 'updates' => 1, 'skips' => 0],
            duplicates: [[
                'id' => 'row-2',
                'plan_version' => 1,
                'row' => 2,
                'label' => 'Morgan Lee',
                'matched_to' => 'Morgan Lee · morgan@example.test',
                'reasons' => ['Same normalized email'],
                'resolution' => $resolution,
                'candidates' => [[
                    'id' => '01JFAKEPERSON0000000000000',
                    'label' => 'Morgan Lee · morgan@example.test',
                    'version' => 4,
                ]],
                'selected_candidate_id' => $resolution === 'update' ? '01JFAKEPERSON0000000000000' : null,
                'household_candidates' => [[
                    'id' => '01JFAKEHOUSEHOLD00000000000',
                    'label' => 'Morgan Household',
                    'version' => 3,
                ]],
                'selected_household_candidate_id' => $resolution !== null ? '01JFAKEHOUSEHOLD00000000000' : null,
            ]],
            canCommit: $canCommit,
        );
    }
}
