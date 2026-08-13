<?php

namespace Tests\Feature\DataPortability;

use App\DataPortability\Actions\BuildCrmPortableExport;
use App\DataPortability\Actions\CommitCrmImport;
use App\DataPortability\Actions\PreviewCrmImport;
use App\DataPortability\Actions\ResolveCrmImportRows;
use App\DataPortability\Actions\StageCrmImport;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\DataPortability\Support\CrmPortableBundle;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\PersonInstrument;
use App\Models\PersonTag;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CrmPortableRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
        Storage::fake('crm_data_portability');
        Queue::fake();
    }

    public function test_complete_bundle_round_trips_into_an_empty_studio_with_portable_references(): void
    {
        $actor = User::factory()->create();
        $source = Studio::factory()->create();
        $target = Studio::factory()->create();
        $sourceMember = $this->membership($source, $actor);
        $targetMember = $this->membership($target, $actor);
        app(TenantContext::class)->activate($source, $sourceMember);
        [$guardian,$student] = $this->sourceGraph($source);

        $builder = app(BuildCrmPortableExport::class);
        $export = $builder->request($source, $actor, 'roundtrip-source');
        $builder->build($source, $actor, $export);
        $bundle = $builder->decryptedArchive($export->fresh());
        $secondExport = $builder->request($source, $actor, 'roundtrip-source-again');
        $builder->build($source, $actor, $secondExport);
        $this->assertSame($bundle, $builder->decryptedArchive($secondExport->fresh()));
        $verified = app(CrmPortableBundle::class)->parse($bundle);
        $this->assertSame(['instruments', 'tags', 'custom_field_definitions', 'households', 'people', 'student_profiles', 'staff_profiles', 'person_instruments', 'person_tags', 'custom_field_values', 'household_members', 'guardian_relationships'], array_keys($verified['datasets']));
        $this->assertStringNotContainsString((string) $guardian->getKey(), $bundle);
        $this->assertStringNotContainsString((string) $student->getKey(), $bundle);

        app(TenantContext::class)->activate($target, $targetMember);
        $batch = app(StageCrmImport::class)->handle($target, $actor, UploadedFile::fake()->createWithContent('source.maestro', $bundle), 'roundtrip-stage');
        $preview = app(PreviewCrmImport::class)->handle($target, $actor, $batch, $batch->version);
        $this->assertSame('ready', $preview['batch']->status);
        $committed = app(CommitCrmImport::class)->handle($target, $actor, $preview['batch'], $preview['batch']->version);
        $this->assertSame('completed', $committed->status);

        $this->assertSame(2, Person::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, Household::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(2, HouseholdMember::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, GuardianRelationship::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, StudentProfile::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, StudentStatusTransition::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, StaffProfile::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, PersonInstrument::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, PersonTag::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(1, CustomFieldValue::query()->where('studio_id', $target->getKey())->count());

        app(TenantContext::class)->activate($target, $targetMember);
        $targetExport = $builder->request($target, $actor, 'roundtrip-target');
        $builder->build($target, $actor, $targetExport);
        $targetBundle = app(CrmPortableBundle::class)->parse($builder->decryptedArchive($targetExport->fresh()));
        $this->assertSame($verified['datasets'], $targetBundle['datasets']);
    }

    public function test_bundle_rejects_nonempty_target_and_decisions_and_commit_time_drift(): void
    {
        $actor = User::factory()->create();
        $source = Studio::factory()->create();
        $target = Studio::factory()->create();
        $sourceMember = $this->membership($source, $actor);
        $targetMember = $this->membership($target, $actor);
        app(TenantContext::class)->activate($source, $sourceMember);
        $this->sourceGraph($source);
        $builder = app(BuildCrmPortableExport::class);
        $export = $builder->request($source, $actor, 'boundary-source');
        $builder->build($source, $actor, $export);
        $bundle = $builder->decryptedArchive($export->fresh());
        app(TenantContext::class)->activate($target, $targetMember);
        Person::factory()->create(['studio_id' => $target->getKey()]);
        $batch = app(StageCrmImport::class)->handle($target, $actor, UploadedFile::fake()->createWithContent('source.maestro', $bundle), 'boundary-nonempty');
        try {
            app(PreviewCrmImport::class)->handle($target, $actor, $batch, $batch->version);
            $this->fail('Expected nonempty conflict.');
        } catch (CrmDataPortabilityConflict $e) {
            $this->assertSame('CRM_PORTABLE_TARGET_NOT_EMPTY', $e->getMessage());
        }
        Person::withTrashed()->where('studio_id', $target->getKey())->forceDelete();
        $fresh = app(StageCrmImport::class)->handle($target, $actor, UploadedFile::fake()->createWithContent('source.maestro', $bundle), 'boundary-fresh');
        $preview = app(PreviewCrmImport::class)->handle($target, $actor, $fresh, $fresh->version);
        $row = $preview['batch']->rows()->firstOrFail();
        try {
            app(ResolveCrmImportRows::class)->handle($target, $actor, $preview['batch'], $preview['batch']->version, [['row_id' => $row->getKey(), 'plan_version' => $row->plan_version, 'decision' => 'skip']]);
            $this->fail('Expected decision conflict.');
        } catch (CrmDataPortabilityConflict $e) {
            $this->assertSame('CRM_PORTABLE_DECISIONS_FORBIDDEN', $e->getMessage());
        }
        Person::factory()->create(['studio_id' => $target->getKey()]);
        try {
            app(CommitCrmImport::class)->handle($target, $actor, $preview['batch'], $preview['batch']->version);
            $this->fail('Expected commit drift conflict.');
        } catch (CrmDataPortabilityConflict $e) {
            $this->assertSame('CRM_PORTABLE_TARGET_NOT_EMPTY', $e->getMessage());
        }
        $this->assertSame(1, Person::query()->where('studio_id', $target->getKey())->count());
        $this->assertSame(0, Household::query()->where('studio_id', $target->getKey())->count());
    }

    private function sourceGraph(Studio $studio): array
    {
        $guardian = Person::factory()->create(['studio_id' => $studio->getKey(), 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test']);
        $student = Person::factory()->create(['studio_id' => $studio->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
        StudentProfile::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $student->getKey(), 'status' => 'active', 'joined_on' => '2026-01-01', 'learning_preferences' => [], 'status_changed_at' => now()]);
        StaffProfile::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $guardian->getKey(), 'roles' => ['teacher'], 'status' => 'active', 'employment_type' => 'employee', 'can_substitute' => true]);
        $household = Household::factory()->create(['studio_id' => $studio->getKey(), 'name' => 'Lovelace Family']);
        foreach ([[$guardian, 'guardian', true, true], [$student, 'learner', false, false]] as [$person,$role,$primary,$billing]) {
            HouseholdMember::query()->create(['studio_id' => $studio->getKey(), 'household_id' => $household->getKey(), 'person_id' => $person->getKey(), 'role' => $role, 'is_primary_contact' => $primary, 'receives_billing' => $billing]);
        }
        GuardianRelationship::query()->create(['studio_id' => $studio->getKey(), 'household_id' => $household->getKey(), 'guardian_person_id' => $guardian->getKey(), 'student_person_id' => $student->getKey(), 'relationship' => 'parent', 'is_legal_guardian' => true, 'is_emergency_contact' => true, 'is_authorized_pickup' => true, 'portal_permissions' => []]);
        $instrument = Instrument::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Piano', 'active' => true]);
        PersonInstrument::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $student->getKey(), 'instrument_id' => $instrument->getKey(), 'relationship' => 'studies', 'proficiency' => 'beginner', 'is_primary' => true, 'years_experience' => 1]);
        $tag = Tag::query()->create(['studio_id' => $studio->getKey(), 'name' => 'Recital', 'color' => '#123456', 'active' => true]);
        PersonTag::query()->create(['studio_id' => $studio->getKey(), 'person_id' => $student->getKey(), 'tag_id' => $tag->getKey()]);
        $definition = CustomFieldDefinition::query()->create(['studio_id' => $studio->getKey(), 'key' => 'shirt-size', 'name' => 'Shirt size', 'type' => 'text', 'applies_to' => 'person', 'required' => false, 'active' => true, 'sort_order' => 4]);
        CustomFieldValue::query()->create(['studio_id' => $studio->getKey(), 'definition_id' => $definition->getKey(), 'person_id' => $student->getKey(), 'value' => ['value' => 'M']]);

        return [$guardian, $student];
    }

    private function membership(Studio $studio, User $actor): StudioMembership
    {
        return StudioMembership::factory()->create(['studio_id' => $studio->getKey(), 'user_id' => $actor->getKey(), 'role' => 'owner', 'status' => 'active']);
    }
}
