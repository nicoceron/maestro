<?php

namespace Tests\Feature\Filament;

use App\Enums\GuardianRelationshipType;
use App\Enums\HouseholdMemberRole;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PortalPermission;
use App\Enums\StudentStatus;
use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Resources\Households\Pages\CreateHousehold;
use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Filament\Resources\Households\Pages\ViewHousehold;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HouseholdResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_table_is_scoped_searchable_and_links_to_safe_view(): void
    {
        [$office, $studio] = $this->member(MembershipRole::Office);
        $otherStudio = Studio::factory()->create();
        $rivera = Household::factory()->for($studio)->create(['name' => 'Rivera household']);
        $patel = Household::factory()->for($studio)->create(['name' => 'Patel household']);
        $other = Household::factory()->for($otherStudio)->create(['name' => 'Hidden household']);
        $this->filamentAs($office, $studio);

        Livewire::test(ListHouseholds::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$rivera, $patel])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('Rivera')
            ->assertCanSeeTableRecords([$rivera])
            ->assertCanNotSeeTableRecords([$patel]);
    }

    public function test_office_can_create_guardian_student_household_with_relationship_permissions(): void
    {
        [$office, $studio] = $this->member(MembershipRole::Office);
        $this->filamentAs($office, $studio);

        Livewire::test(CreateHousehold::class)
            ->fillForm($this->payload())
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $household = Household::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('Rivera household', $household->name);
        $this->assertSame(1, $household->version);
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->getKey(),
            'role' => HouseholdMemberRole::Guardian->value,
            'is_primary_contact' => true,
            'receives_billing' => true,
        ]);
        $student = Person::query()->where('first_name', 'Lucía')->sole();
        $this->assertSame(StudentStatus::Trial, $student->studentProfile->status);
        $this->assertDatabaseHas('guardian_relationships', [
            'household_id' => $household->getKey(),
            'student_person_id' => $student->getKey(),
            'relationship' => GuardianRelationshipType::Parent->value,
            'is_legal_guardian' => true,
            'is_emergency_contact' => true,
            'is_authorized_pickup' => true,
        ]);
    }

    public function test_household_create_rejects_missing_primary_contact_and_invalid_relationship_keys(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $payload = $this->payload();
        $payload['members'][0]['is_primary_contact'] = false;
        $payload['relationships'][0]['guardian_key'] = 'unknown';

        Livewire::test(CreateHousehold::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasFormErrors(['relationships.0.guardian_key']);

        $payload['relationships'][0]['guardian_key'] = 'sofia';

        Livewire::test(CreateHousehold::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasFormErrors(['members']);

        $this->assertDatabaseEmpty('households');
        $this->assertDatabaseEmpty('people');
    }

    public function test_household_create_requires_an_emailed_billing_contact_and_consistent_billing_portal_access(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        $payload = $this->payload();
        $payload['members'][0]['receives_billing'] = false;

        Livewire::test(CreateHousehold::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasFormErrors([
                'members',
                'relationships.0.portal_permissions',
            ]);

        $payload['members'][0]['receives_billing'] = true;
        $payload['members'][0]['email'] = null;

        Livewire::test(CreateHousehold::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasFormErrors(['members.0.email']);

        $this->assertDatabaseEmpty('households');
        $this->assertDatabaseEmpty('people');
    }

    public function test_billing_view_redacts_notes_nonpayer_contact_birth_and_guardian_flags(): void
    {
        [$billing, $studio] = $this->member(MembershipRole::Billing);
        $household = Household::factory()->for($studio)->create([
            'name' => 'Private family',
            'notes' => 'Private custody note',
        ]);
        $payer = Person::factory()->for($studio)->create([
            'first_name' => 'Payer',
            'email' => 'payer@example.test',
            'phone' => '+15550101',
            'birth_date' => '1985-02-03',
        ]);
        $learner = Person::factory()->for($studio)->create([
            'first_name' => 'Learner',
            'email' => 'private@example.test',
            'phone' => '+15550102',
            'birth_date' => '2015-04-12',
        ]);
        foreach ([[$payer, HouseholdMemberRole::Guardian, true], [$learner, HouseholdMemberRole::Learner, false]] as [$person, $role, $payerFlag]) {
            HouseholdMember::query()->create([
                'studio_id' => $studio->getKey(),
                'household_id' => $household->getKey(),
                'person_id' => $person->getKey(),
                'role' => $role,
                'is_primary_contact' => $payerFlag,
                'receives_billing' => $payerFlag,
            ]);
        }
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $learner->getKey(),
            'status' => StudentStatus::Active,
            'school_grade' => 'Private grade',
            'learning_preferences' => [],
        ]);
        GuardianRelationship::query()->create([
            'studio_id' => $studio->getKey(),
            'household_id' => $household->getKey(),
            'guardian_person_id' => $payer->getKey(),
            'student_person_id' => $learner->getKey(),
            'relationship' => GuardianRelationshipType::LegalGuardian,
            'is_legal_guardian' => true,
            'is_emergency_contact' => true,
            'is_authorized_pickup' => true,
            'portal_permissions' => PortalPermission::defaults(),
        ]);
        $this->filamentAs($billing, $studio);

        $this->assertFalse(HouseholdResource::canCreate());
        Livewire::test(ViewHousehold::class, ['record' => $household->getKey()])
            ->assertSuccessful()
            ->assertSee('payer@example.test')
            ->assertDontSee('private@example.test')
            ->assertDontSee('Private custody note')
            ->assertDontSee('Private grade')
            ->assertDontSee('Legal guardian')
            ->assertDontSee('1985')
            ->assertDontSee('2015');
    }

    public function test_owner_can_edit_versioned_household_aggregate_and_relationship_permissions(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);

        Livewire::test(CreateHousehold::class)
            ->fillForm($this->payload())
            ->call('create')
            ->assertHasNoFormErrors();

        $household = Household::query()->where('studio_id', $studio->getKey())->sole();
        $component = Livewire::test(EditHousehold::class, ['record' => $household->getKey()])
            ->assertSuccessful()
            ->assertFormSet([
                'name' => 'Rivera household',
                'version' => 1,
            ]);
        $data = $component->get('data');
        $data['name'] = 'Rivera & Torres household';

        foreach ($data['members'] as &$member) {
            if ($member['first_name'] === 'Sofía') {
                $member['phone'] = '+57 300 555 9999';
            }

            if ($member['first_name'] === 'Lucía') {
                $member['student']['school_grade'] = '6';
            }
        }
        unset($member);
        $relationshipKey = array_key_first($data['relationships']);
        $data['relationships'][$relationshipKey]['is_authorized_pickup'] = false;
        $data['relationships'][$relationshipKey]['portal_permissions'] = [
            PortalPermission::Calendar->value,
            PortalPermission::Learning->value,
        ];

        $component
            ->fillForm($data)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $household->refresh();
        $this->assertSame('Rivera & Torres household', $household->name);
        $this->assertSame(2, $household->version);
        $this->assertDatabaseHas('people', [
            'studio_id' => $studio->getKey(),
            'first_name' => 'Sofía',
            'phone' => '+57 300 555 9999',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'studio_id' => $studio->getKey(),
            'school_grade' => '6',
            'status' => StudentStatus::Trial->value,
        ]);
        $relationship = GuardianRelationship::query()->where('household_id', $household->getKey())->sole();
        $this->assertFalse($relationship->is_authorized_pickup);
        $this->assertSame([
            PortalPermission::Calendar->value,
            PortalPermission::Learning->value,
        ], $relationship->portal_permissions);
    }

    public function test_household_edit_rejects_stale_version_without_overwriting_changes(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        Livewire::test(CreateHousehold::class)
            ->fillForm($this->payload())
            ->call('create')
            ->assertHasNoFormErrors();

        $household = Household::query()->where('studio_id', $studio->getKey())->sole();
        $component = Livewire::test(EditHousehold::class, ['record' => $household->getKey()]);
        $household->update(['name' => 'Changed elsewhere', 'version' => 2]);

        $component
            ->set('data.name', 'Stale browser change')
            ->call('save')
            ->assertHasFormErrors(['version']);

        $this->assertSame('Changed elsewhere', $household->refresh()->name);
        $this->assertSame(2, $household->version);
    }

    public function test_household_edit_revalidates_billing_invariants_before_aggregate_write(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);
        Livewire::test(CreateHousehold::class)
            ->fillForm($this->payload())
            ->call('create')
            ->assertHasNoFormErrors();

        $household = Household::query()->where('studio_id', $studio->getKey())->sole();
        $component = Livewire::test(EditHousehold::class, ['record' => $household->getKey()]);
        $data = $component->get('data');

        foreach ($data['members'] as &$member) {
            $member['receives_billing'] = false;
        }
        unset($member);

        $component
            ->fillForm($data)
            ->call('save')
            ->assertHasFormErrors([
                'members',
                'relationships.0.portal_permissions',
            ]);

        $this->assertSame(1, $household->refresh()->version);
        $this->assertDatabaseHas('household_members', [
            'household_id' => $household->getKey(),
            'receives_billing' => true,
        ]);
    }

    public function test_billing_member_cannot_edit_household(): void
    {
        [$billing, $studio] = $this->member(MembershipRole::Billing);
        $household = Household::factory()->for($studio)->create();
        $this->filamentAs($billing, $studio);

        $this->assertFalse(HouseholdResource::canEdit($household));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'name' => 'Rivera household',
            'notes' => 'Prefers afternoon calls.',
            'members' => [
                [
                    'key' => 'sofia',
                    'first_name' => 'Sofía',
                    'last_name' => 'Rivera',
                    'email' => 'sofia@example.test',
                    'phone' => '+57 300 555 0101',
                    'household_role' => HouseholdMemberRole::Guardian->value,
                    'is_primary_contact' => true,
                    'receives_billing' => true,
                ],
                [
                    'key' => 'lucia',
                    'first_name' => 'Lucía',
                    'last_name' => 'Rivera',
                    'birth_date' => '2015-04-12',
                    'household_role' => HouseholdMemberRole::Learner->value,
                    'is_primary_contact' => false,
                    'receives_billing' => false,
                    'student' => [
                        'status' => StudentStatus::Trial->value,
                        'joined_on' => '2026-08-10',
                        'school_grade' => '5',
                    ],
                ],
            ],
            'relationships' => [[
                'guardian_key' => 'sofia',
                'student_key' => 'lucia',
                'relationship' => GuardianRelationshipType::Parent->value,
                'is_legal_guardian' => true,
                'is_emergency_contact' => true,
                'is_authorized_pickup' => true,
                'portal_permissions' => [
                    PortalPermission::Calendar->value,
                    PortalPermission::Attendance->value,
                    PortalPermission::Learning->value,
                    PortalPermission::Billing->value,
                ],
            ]],
        ];
    }

    private function filamentAs(User $user, Studio $studio): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $membership = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->sole();
        app(TenantContext::class)->activate($studio, $membership);
    }

    /** @return array{User, Studio} */
    private function member(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);

        return [$user, $studio];
    }
}
