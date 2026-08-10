<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HouseholdApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_households(): void
    {
        $studio = Studio::factory()->create();

        $this->getJson("/api/v1/studios/{$studio->slug}/households")
            ->assertUnauthorized();
    }

    public function test_office_staff_can_create_a_household_aggregate_with_guardian_privacy_rules(): void
    {
        [$user, $studio] = $this->authenticatedMember(MembershipRole::Office);

        $response = $this->postJson(
            "/api/v1/studios/{$studio->slug}/households",
            $this->householdPayload(),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Household created.')
            ->assertJsonPath('data.name', 'Rivera household')
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.members.0.role', 'guardian')
            ->assertJsonPath('data.members.0.person.display_name', 'Sofía Rivera')
            ->assertJsonPath('data.members.1.role', 'learner')
            ->assertJsonPath('data.members.1.person.student.status', 'trial')
            ->assertJsonPath('data.guardian_relationships.0.relationship', 'parent')
            ->assertJsonPath('data.guardian_relationships.0.portal_permissions.0', 'calendar')
            ->assertJsonPath('data.permissions.edit', true)
            ->assertJsonPath('data.permissions.delete', false);

        $householdId = $response->json('data.id');
        $guardianId = $response->json('data.members.0.person.id');
        $studentId = $response->json('data.members.1.person.id');

        $this->assertDatabaseHas('households', [
            'id' => $householdId,
            'studio_id' => $studio->getKey(),
        ]);
        $this->assertDatabaseHas('guardian_relationships', [
            'studio_id' => $studio->getKey(),
            'household_id' => $householdId,
            'guardian_person_id' => $guardianId,
            'student_person_id' => $studentId,
            'is_legal_guardian' => true,
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'studio_id' => $studio->getKey(),
            'person_id' => $studentId,
            'status' => 'trial',
        ]);
        $this->assertFalse(app(TenantContext::class)->hasStudio());
        $this->assertSame($user->getKey(), auth()->id());
    }

    public function test_household_lists_are_tenant_scoped_searchable_and_paginated(): void
    {
        [, $studio] = $this->authenticatedMember(MembershipRole::Administrator);
        $otherStudio = Studio::factory()->create();
        Household::factory()->for($studio)->create(['name' => 'Allegro family']);
        Household::factory()->for($studio)->create(['name' => 'Nocturne family']);
        Household::factory()->for($otherStudio)->create(['name' => 'Allegro outsider']);

        $this->getJson("/api/v1/studios/{$studio->slug}/households?q=allegro&per_page=10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Allegro family')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['name' => 'Allegro outsider']);
    }

    public function test_search_can_find_a_household_by_a_member_name(): void
    {
        [, $studio] = $this->authenticatedMember(MembershipRole::Billing);
        $household = Household::factory()->for($studio)->create(['name' => 'Account 104']);
        $person = Person::factory()->for($studio)->create([
            'first_name' => 'Mateo',
            'last_name' => 'Hernández',
        ]);
        HouseholdMember::query()->create([
            'studio_id' => $studio->getKey(),
            'household_id' => $household->getKey(),
            'person_id' => $person->getKey(),
            'role' => 'guardian',
            'is_primary_contact' => true,
            'receives_billing' => true,
        ]);

        $this->getJson("/api/v1/studios/{$studio->slug}/households?q=mateo")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $household->getKey())
            ->assertJsonPath('data.0.permissions.edit', false);
    }

    public function test_billing_staff_receive_only_payer_contact_fields_not_private_family_data(): void
    {
        [, $studio] = $this->authenticatedMember(MembershipRole::Billing);
        $household = Household::factory()->for($studio)->create([
            'name' => 'Privacy household',
            'notes' => 'Private custody note',
        ]);
        $guardian = Person::factory()->for($studio)->create([
            'email' => 'payer@example.test',
            'birth_date' => '1984-02-05',
            'pronouns' => 'she/her',
        ]);
        $learner = Person::factory()->for($studio)->create([
            'email' => 'learner@example.test',
            'phone' => '+1 555 0102',
            'birth_date' => '2016-06-15',
            'pronouns' => 'they/them',
        ]);
        HouseholdMember::query()->create([
            'studio_id' => $studio->getKey(),
            'household_id' => $household->getKey(),
            'person_id' => $guardian->getKey(),
            'role' => 'guardian',
            'is_primary_contact' => true,
            'receives_billing' => true,
        ]);
        HouseholdMember::query()->create([
            'studio_id' => $studio->getKey(),
            'household_id' => $household->getKey(),
            'person_id' => $learner->getKey(),
            'role' => 'learner',
            'is_primary_contact' => false,
            'receives_billing' => false,
        ]);

        $this->getJson("/api/v1/studios/{$studio->slug}/households/{$household->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.members.0.person.email', 'payer@example.test')
            ->assertJsonPath('data.members.0.person.birth_date', null)
            ->assertJsonPath('data.members.0.person.pronouns', null)
            ->assertJsonPath('data.members.1.person.email', null)
            ->assertJsonPath('data.members.1.person.phone', null)
            ->assertJsonPath('data.members.1.person.birth_date', null)
            ->assertJsonPath('data.members.1.person.pronouns', null)
            ->assertJsonCount(0, 'data.guardian_relationships');
    }

    public function test_household_ids_cannot_be_used_across_studios(): void
    {
        [, $studio] = $this->authenticatedMember(MembershipRole::Owner);
        $otherHousehold = Household::factory()->create();

        $this->getJson(
            "/api/v1/studios/{$studio->slug}/households/{$otherHousehold->getKey()}",
        )->assertNotFound();
    }

    public function test_database_constraints_reject_cross_studio_household_members(): void
    {
        $firstStudio = Studio::factory()->create();
        $secondStudio = Studio::factory()->create();
        $household = Household::factory()->for($firstStudio)->create();
        $person = Person::factory()->for($secondStudio)->create();

        $this->expectException(QueryException::class);

        HouseholdMember::query()->create([
            'studio_id' => $firstStudio->getKey(),
            'household_id' => $household->getKey(),
            'person_id' => $person->getKey(),
            'role' => 'learner',
            'is_primary_contact' => false,
            'receives_billing' => false,
        ]);
    }

    public function test_members_cannot_enter_an_unrelated_or_suspended_studio(): void
    {
        $user = User::factory()->create();
        $ownStudio = Studio::factory()->create();
        $otherStudio = Studio::factory()->create();
        $suspendedStudio = Studio::factory()->create();
        $this->membership($user, $ownStudio, MembershipRole::Administrator);
        $this->membership(
            $user,
            $suspendedStudio,
            MembershipRole::Owner,
            MembershipStatus::Suspended,
        );
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/studios/{$otherStudio->slug}/households")
            ->assertForbidden();
        $this->getJson("/api/v1/studios/{$suspendedStudio->slug}/households")
            ->assertForbidden();
    }

    public function test_teachers_and_billing_staff_cannot_create_households(): void
    {
        [, $teacherStudio] = $this->authenticatedMember(MembershipRole::Teacher);

        $this->postJson(
            "/api/v1/studios/{$teacherStudio->slug}/households",
            $this->householdPayload(),
        )->assertForbidden();

        [, $billingStudio] = $this->authenticatedMember(MembershipRole::Billing);

        $this->postJson(
            "/api/v1/studios/{$billingStudio->slug}/households",
            $this->householdPayload(),
        )->assertForbidden();
    }

    public function test_household_validation_rejects_ambiguous_contacts_and_relationships(): void
    {
        [, $studio] = $this->authenticatedMember(MembershipRole::Administrator);
        $payload = $this->householdPayload();
        $payload['members'][0]['is_primary_contact'] = false;
        $payload['relationships'][0]['student_key'] = 'missing-student';

        $this->postJson(
            "/api/v1/studios/{$studio->slug}/households",
            $payload,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'members',
                'relationships.0.student_key',
            ]);

        $this->assertDatabaseEmpty('households');
        $this->assertDatabaseEmpty('people');
    }

    /** @return array{0: User, 1: Studio} */
    private function authenticatedMember(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        $this->membership($user, $studio, $role);
        Sanctum::actingAs($user);

        return [$user, $studio];
    }

    private function membership(
        User $user,
        Studio $studio,
        MembershipRole $role,
        MembershipStatus $status = MembershipStatus::Active,
    ): StudioMembership {
        return StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
            'preferences' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function householdPayload(): array
    {
        return [
            'name' => 'Rivera household',
            'members' => [
                [
                    'key' => 'sofia',
                    'first_name' => 'Sofía',
                    'last_name' => 'Rivera',
                    'email' => 'sofia@example.test',
                    'phone' => '+57 300 555 0101',
                    'household_role' => 'guardian',
                    'is_primary_contact' => true,
                    'receives_billing' => true,
                ],
                [
                    'key' => 'lucia',
                    'first_name' => 'Lucía',
                    'last_name' => 'Rivera',
                    'birth_date' => '2015-04-12',
                    'household_role' => 'learner',
                    'is_primary_contact' => false,
                    'receives_billing' => false,
                    'student' => [
                        'status' => 'trial',
                        'joined_on' => '2026-08-10',
                        'school_grade' => '5',
                    ],
                ],
            ],
            'relationships' => [
                [
                    'guardian_key' => 'sofia',
                    'student_key' => 'lucia',
                    'relationship' => 'parent',
                    'is_legal_guardian' => true,
                    'is_emergency_contact' => true,
                    'is_authorized_pickup' => true,
                    'portal_permissions' => ['calendar', 'attendance', 'learning', 'billing'],
                ],
            ],
        ];
    }
}
