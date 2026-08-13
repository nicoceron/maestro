<?php

namespace Tests\Feature\Api;

use App\Actions\People\CreatePerson;
use App\Actions\People\UpdatePerson;
use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudentStatus;
use App\Models\CustomFieldDefinition;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\StudentStatusTransition;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PeopleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_people(): void
    {
        $studio = Studio::factory()->create();

        $this->getJson("/api/v1/studios/{$studio->slug}/people")
            ->assertUnauthorized();
    }

    public function test_office_can_create_a_student_with_taxonomy_and_typed_custom_fields(): void
    {
        [$office, $studio, $membership] = $this->member(MembershipRole::Office);
        [$instrument, $tag, $field] = $this->taxonomy($studio, $membership);

        $response = $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => '  Maya  ',
            'last_name' => 'Rivera',
            'email' => 'MAYA@Example.Test',
            'phone' => '+1 555 0100',
            'source' => 'website',
            'external_reference' => 'CRM-1042',
            'preferred_locale' => 'es-CO',
            'student' => [
                'status' => 'lead',
                'school_grade' => '6',
                'lead_source' => 'summer-campaign',
                'learning_preferences' => ['visual', 'short-sessions'],
            ],
            'tag_ids' => [$tag->getKey()],
            'instruments' => [[
                'instrument_id' => $instrument->getKey(),
                'relationship' => 'studies',
                'proficiency' => 'beginner',
                'is_primary' => true,
                'years_experience' => 1,
            ]],
            'custom_fields' => [[
                'definition_id' => $field->getKey(),
                'value' => 'Saturday mornings',
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Person created.')
            ->assertJsonPath('data.display_name', 'Maya Rivera')
            ->assertJsonPath('data.email', 'maya@example.test')
            ->assertJsonPath('data.student.status', 'lead')
            ->assertJsonPath('data.instruments.0.name', 'Piano')
            ->assertJsonPath('data.tags.0.name', 'Priority lead')
            ->assertJsonPath('data.custom_fields.0.value', 'Saturday mornings')
            ->assertJsonPath('data.permissions.transition_student', true);

        $personId = $response->json('data.id');
        $this->assertDatabaseHas('people', [
            'id' => $personId,
            'studio_id' => $studio->getKey(),
            'email' => 'maya@example.test',
            'external_reference' => 'CRM-1042',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'studio_id' => $studio->getKey(),
            'person_id' => $personId,
            'status' => StudentStatus::Lead->value,
        ]);
        $this->assertDatabaseHas('person_instruments', [
            'studio_id' => $studio->getKey(),
            'person_id' => $personId,
            'instrument_id' => $instrument->getKey(),
        ]);
        $this->assertSame($office->getKey(), auth()->id());
        $this->assertFalse(app(TenantContext::class)->hasStudio());
    }

    public function test_cross_tenant_assignments_fail_atomically(): void
    {
        [$administrator, $studio] = $this->member(MembershipRole::Administrator);
        [, $otherStudio, $otherMembership] = $this->member(MembershipRole::Owner);
        [, $foreignTag] = $this->catalogBasics($otherStudio, $otherMembership);
        Sanctum::actingAs($administrator);

        $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Noah',
            'tag_ids' => [$foreignTag->getKey()],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tag_ids');

        $this->assertDatabaseMissing('people', [
            'studio_id' => $studio->getKey(),
            'first_name' => 'Noah',
        ]);
    }

    public function test_owner_can_create_a_multi_role_staff_profile(): void
    {
        [, $studio] = $this->member(MembershipRole::Owner);

        $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Jordan',
            'last_name' => 'Lee',
            'staff' => [
                'roles' => ['teacher', 'substitute'],
                'status' => 'active',
                'employment_type' => 'contractor',
                'bio' => 'Piano and composition teacher.',
                'hire_on' => '2026-08-01',
                'can_substitute' => true,
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.staff.roles.0', 'teacher')
            ->assertJsonPath('data.staff.roles.1', 'substitute')
            ->assertJsonPath('data.staff.employment_type', 'contractor');
    }

    public function test_student_lifecycle_uses_valid_transitions_and_an_immutable_history(): void
    {
        [$owner, $studio, $membership] = $this->member(MembershipRole::Owner);
        $person = $this->student($studio, $membership, StudentStatus::Lead);

        $this->postJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}/student-status", [
            'version' => 1,
            'status' => 'trial',
            'reason' => 'Intro lesson booked.',
        ])
            ->assertOk()
            ->assertJsonPath('data.student.status', 'trial')
            ->assertJsonPath('data.student_status_history.0.previous_status', 'lead')
            ->assertJsonPath('data.student_status_history.0.new_status', 'trial');

        $this->postJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}/student-status", [
            'version' => 2,
            'status' => 'active',
        ])
            ->assertOk()
            ->assertJsonPath('data.student.status', 'active')
            ->assertJsonPath('data.version', 3);

        $this->postJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}/student-status", [
            'version' => 3,
            'status' => 'trial',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->withTenant($studio, $membership, function () use ($owner): void {
            $transition = StudentStatusTransition::query()->firstOrFail();

            try {
                $transition->forceFill(['reason' => 'rewritten'])->save();
                $this->fail('The database accepted an audit history update.');
            } catch (\LogicException|QueryException) {
                $this->assertTrue(true);
            }

            $this->assertSame($owner->getKey(), $transition->actor_id);
        });
    }

    public function test_updates_require_the_current_version_and_cannot_bypass_lifecycle(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Office);
        $person = $this->student($studio, $membership, StudentStatus::Active);

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 1,
            'preferred_name' => 'M',
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.preferred_name', 'M');

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 1,
            'preferred_name' => 'Stale',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 2,
            'student' => ['status' => 'former'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student.status');

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 2,
            'student' => ['left_on' => '2026-08-12'],
        ])->assertUnprocessable()->assertJsonValidationErrors('student.left_on');

        $this->assertSame('M', $person->refresh()->preferred_name);
        $this->assertSame(StudentStatus::Active, $person->studentProfile->status);
    }

    public function test_person_mutations_reject_stale_transitions_invalid_names_duplicate_references_and_incomplete_profiles(): void
    {
        [$office, $studio, $membership] = $this->member(MembershipRole::Office);
        $person = $this->student($studio, $membership, StudentStatus::Lead);

        $this->postJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}/student-status", [
            'version' => 2,
            'status' => 'trial',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 1,
            'first_name' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors('first_name');

        $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Existing',
            'external_reference' => ' CRM-9 ',
        ])->assertCreated();
        $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Duplicate',
            'external_reference' => 'CRM-9',
        ])->assertUnprocessable()->assertJsonValidationErrors('external_reference');

        $this->withTenant($studio, $membership, function () use ($office, $person): void {
            try {
                app(CreatePerson::class)->handle([
                    'first_name' => 'Concurrent duplicate',
                    'external_reference' => 'CRM-9',
                ], $office);
                $this->fail('The action did not map a database-level external reference collision.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('external_reference', $exception->errors());
            }

            try {
                app(UpdatePerson::class)->handle(
                    $person,
                    ['external_reference' => 'CRM-9'],
                    $person->refresh()->version,
                    $office,
                );
                $this->fail('The update action did not map a database-level external reference collision.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('external_reference', $exception->errors());
            }
        });

        $plain = $this->withTenant(
            $studio,
            $membership,
            fn (): Person => Person::factory()->for($studio)->create(),
        );
        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$plain->getKey()}", [
            'version' => 1,
            'student' => ['school_grade' => '7'],
        ])->assertUnprocessable()->assertJsonValidationErrors('student.status');
    }

    public function test_student_history_is_initially_audited_and_status_cannot_be_updated_directly(): void
    {
        [$owner, $studio, $membership] = $this->member(MembershipRole::Owner);

        $created = $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Audited',
            'student' => ['status' => 'lead'],
        ])->assertCreated();

        $personId = $created->json('data.id');
        $this->getJson("/api/v1/studios/{$studio->slug}/people/{$personId}")
            ->assertOk()
            ->assertJsonPath('data.student_status_history.0.previous_status', null)
            ->assertJsonPath('data.student_status_history.0.new_status', 'lead')
            ->assertJsonMissingPath('data.student_status_history.0.actor_id');

        $this->withTenant($studio, $membership, function () use ($owner, $personId): void {
            $profile = Person::query()->findOrFail($personId)->studentProfile()->firstOrFail();
            $transition = $profile->statusTransitions()->firstOrFail();
            $this->assertSame($owner->getKey(), $transition->actor_id);
            $usesSavepoint = DB::getDriverName() === 'pgsql';

            if ($usesSavepoint) {
                DB::statement('SAVEPOINT reject_direct_student_status');
            }

            try {
                $profile->forceFill(['status' => StudentStatus::Active])->save();
                $this->fail('The database accepted a direct student status update.');
            } catch (QueryException) {
                if ($usesSavepoint) {
                    DB::statement('ROLLBACK TO SAVEPOINT reject_direct_student_status');
                }

                $this->assertTrue(true);
            } finally {
                if ($usesSavepoint) {
                    DB::statement('RELEASE SAVEPOINT reject_direct_student_status');
                }
            }
        });
    }

    public function test_staff_profiles_are_retired_not_deleted_and_mismatched_custom_fields_are_rejected(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Owner);
        $person = $this->withTenant($studio, $membership, fn (): Person => Person::factory()
            ->for($studio)
            ->create());
        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 1,
            'staff' => ['roles' => ['teacher'], 'status' => 'active'],
        ])->assertOk()->assertJsonPath('data.staff.status', 'active');

        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 2,
            'staff' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('staff');
        $this->assertDatabaseHas('staff_profiles', [
            'person_id' => $person->getKey(),
            'status' => 'active',
        ]);

        $field = $this->withTenant($studio, $membership, fn (): CustomFieldDefinition => CustomFieldDefinition::query()->create([
            'studio_id' => $studio->getKey(),
            'key' => 'staff-code',
            'name' => 'Staff code',
            'type' => CustomFieldType::Text,
            'applies_to' => CustomFieldAppliesTo::Student,
            'required' => false,
            'active' => true,
        ]));
        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 2,
            'custom_fields' => [[
                'definition_id' => $field->getKey(),
                'value' => 'X-7',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_fields');
    }

    public function test_billing_sees_only_payer_contact_fields_and_no_private_profile_data(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Billing);
        [$payer, $learner] = $this->withTenant($studio, $membership, function () use ($studio): array {
            $household = Household::factory()->for($studio)->create();
            $payer = Person::factory()->for($studio)->create([
                'email' => 'payer@example.test',
                'birth_date' => '1988-01-10',
                'pronouns' => 'she/her',
            ]);
            $learner = Person::factory()->for($studio)->create([
                'email' => 'learner@example.test',
                'birth_date' => '2014-03-04',
            ]);
            HouseholdMember::query()->create([
                'studio_id' => $studio->getKey(),
                'household_id' => $household->getKey(),
                'person_id' => $payer->getKey(),
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

            return [$payer, $learner];
        });

        $this->getJson("/api/v1/studios/{$studio->slug}/people/{$payer->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.email', 'payer@example.test')
            ->assertJsonPath('data.birth_date', null)
            ->assertJsonPath('data.pronouns', null)
            ->assertJsonPath('data.permissions.edit', false);

        $this->getJson("/api/v1/studios/{$studio->slug}/people/{$learner->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.student', null)
            ->assertJsonPath('data.households', []);

        $this->getJson("/api/v1/studios/{$studio->slug}/people?q=learner%40example.test")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/studios/{$studio->slug}/people?q=payer%40example.test")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payer->getKey());
        $this->getJson("/api/v1/studios/{$studio->slug}/people?student_status=lead")
            ->assertForbidden();
    }

    public function test_people_filters_are_tenant_safe_and_teacher_access_is_denied(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Administrator);
        $lead = $this->student($studio, $membership, StudentStatus::Lead, 'Alpha');
        $active = $this->student($studio, $membership, StudentStatus::Active, 'Bravo');

        $this->getJson("/api/v1/studios/{$studio->slug}/people?student_status=lead&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->getKey())
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['id' => $active->getKey()]);

        [$teacher] = $this->member(MembershipRole::Teacher, $studio);
        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/studios/{$studio->slug}/people")
            ->assertForbidden();
    }

    public function test_people_update_is_patch_only(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Administrator);
        $person = $this->withTenant($studio, $membership, fn (): Person => Person::factory()->for($studio)->create());

        $this->putJson("/api/v1/studios/{$studio->slug}/people/{$person->getKey()}", [
            'version' => 1,
            'first_name' => 'Replacement',
        ])->assertMethodNotAllowed();
    }

    public function test_existing_inactive_taxonomy_assignments_can_be_preserved_but_not_newly_added(): void
    {
        [, $studio, $membership] = $this->member(MembershipRole::Administrator);
        [$instrument, $tag] = $this->catalogBasics($studio, $membership);
        $created = $this->postJson("/api/v1/studios/{$studio->slug}/people", [
            'first_name' => 'Catalogued',
            'tag_ids' => [$tag->getKey()],
            'instruments' => [[
                'instrument_id' => $instrument->getKey(),
                'relationship' => 'studies',
            ]],
        ])->assertCreated();

        $this->withTenant($studio, $membership, function () use ($instrument, $tag): void {
            $instrument->update(['active' => false]);
            $tag->update(['active' => false]);
        });
        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$created->json('data.id')}", [
            'version' => 1,
            'preferred_name' => 'Catalogue',
            'tag_ids' => [$tag->getKey()],
            'instruments' => [[
                'instrument_id' => $instrument->getKey(),
                'relationship' => 'studies',
            ]],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $other = $this->withTenant($studio, $membership, fn (): Person => Person::factory()->for($studio)->create());
        $this->patchJson("/api/v1/studios/{$studio->slug}/people/{$other->getKey()}", [
            'version' => 1,
            'tag_ids' => [$tag->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
    }

    /** @return array{User, Studio, StudioMembership} */
    private function member(MembershipRole $role, ?Studio $studio = null): array
    {
        $user = User::factory()->create();
        $studio ??= Studio::factory()->create();
        $membership = StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        Sanctum::actingAs($user);

        return [$user, $studio, $membership];
    }

    /** @return array{Instrument, Tag, CustomFieldDefinition} */
    private function taxonomy(Studio $studio, StudioMembership $membership): array
    {
        [$instrument, $tag] = $this->catalogBasics($studio, $membership);
        $field = $this->withTenant($studio, $membership, fn (): CustomFieldDefinition => CustomFieldDefinition::query()->create([
            'studio_id' => $studio->getKey(),
            'key' => 'preferred-slot',
            'name' => 'Preferred slot',
            'type' => CustomFieldType::Text,
            'applies_to' => CustomFieldAppliesTo::Student,
            'required' => true,
            'active' => true,
        ]));

        return [$instrument, $tag, $field];
    }

    /** @return array{Instrument, Tag} */
    private function catalogBasics(Studio $studio, StudioMembership $membership): array
    {
        return $this->withTenant($studio, $membership, fn (): array => [
            Instrument::query()->create([
                'studio_id' => $studio->getKey(),
                'name' => 'Piano',
                'normalized_name' => 'piano',
                'active' => true,
            ]),
            Tag::query()->create([
                'studio_id' => $studio->getKey(),
                'name' => 'Priority lead',
                'normalized_name' => 'priority lead',
                'color' => '#4F46E5',
            ]),
        ]);
    }

    private function student(
        Studio $studio,
        StudioMembership $membership,
        StudentStatus $status,
        string $firstName = 'Maya',
    ): Person {
        return $this->withTenant($studio, $membership, function () use ($studio, $status, $firstName): Person {
            $person = Person::factory()->for($studio)->create(['first_name' => $firstName]);
            $person->studentProfile()->create([
                'studio_id' => $studio->getKey(),
                'status' => $status,
                'learning_preferences' => [],
                'status_changed_at' => now(),
            ]);

            return $person;
        });
    }

    /** @template T @param callable(): T $callback @return T */
    private function withTenant(Studio $studio, StudioMembership $membership, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->activate($studio, $membership);

        try {
            return $callback();
        } finally {
            $context->clear();
        }
    }
}
