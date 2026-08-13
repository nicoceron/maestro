<?php

namespace Tests\Feature\Filament;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Enums\EmploymentType;
use App\Enums\InstrumentRelationship;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\PersonStatus;
use App\Enums\ProficiencyLevel;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Models\CustomFieldDefinition;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\PersonInstrument;
use App\Models\PersonTag;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_directory_is_tenant_scoped_searchable_and_filterable(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $otherStudio = Studio::factory()->create();
        $lead = Person::factory()->for($studio)->create([
            'first_name' => 'Visible',
            'last_name' => 'Student',
        ]);
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $lead->getKey(),
            'status' => StudentStatus::Lead,
            'learning_preferences' => [],
        ]);
        $contact = Person::factory()->for($studio)->create([
            'first_name' => 'Family',
            'last_name' => 'Contact',
        ]);
        $hidden = Person::factory()->for($otherStudio)->create([
            'first_name' => 'Hidden',
            'last_name' => 'Student',
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(ListPeople::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$lead, $contact])
            ->assertCanNotSeeTableRecords([$hidden])
            ->searchTable('Visible')
            ->assertCanSeeTableRecords([$lead])
            ->assertCanNotSeeTableRecords([$contact])
            ->searchTable('')
            ->filterTable('student_status', StudentStatus::Lead->value)
            ->assertCanSeeTableRecords([$lead])
            ->assertCanNotSeeTableRecords([$contact])
            ->removeTableFilter('student_status')
            ->filterTable('is_student', true)
            ->assertCanSeeTableRecords([$lead])
            ->assertCanNotSeeTableRecords([$contact]);
    }

    public function test_owner_can_create_a_full_student_and_staff_person_through_domain_action(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $piano = Instrument::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Piano',
            'normalized_name' => 'piano',
            'active' => true,
        ]);
        $vip = Tag::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'VIP',
            'normalized_name' => 'vip',
            'active' => true,
        ]);
        $pronunciation = CustomFieldDefinition::query()->create([
            'studio_id' => $studio->getKey(),
            'key' => 'name_pronunciation',
            'name' => 'Name pronunciation',
            'type' => CustomFieldType::Text,
            'applies_to' => CustomFieldAppliesTo::Person,
            'options' => null,
            'required' => true,
            'active' => true,
            'sort_order' => 1,
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(CreatePerson::class)
            ->fillForm([
                'first_name' => 'Maya',
                'last_name' => 'Rivera',
                'preferred_name' => 'May',
                'email' => 'MAYA@example.test',
                'phone' => '+1 555 0100',
                'birth_date' => '2014-06-15',
                'preferred_locale' => 'es',
                'is_student' => true,
                'student' => [
                    'status' => StudentStatus::Lead->value,
                    'school_grade' => '6',
                    'lead_source' => 'Referral',
                    'learning_preferences' => ['visual examples'],
                ],
                'is_staff' => true,
                'staff' => [
                    'roles' => [StaffRole::Teacher->value, StaffRole::Substitute->value],
                    'status' => StaffStatus::Active->value,
                    'employment_type' => EmploymentType::Contractor->value,
                    'can_substitute' => true,
                ],
                'instruments' => [[
                    'instrument_id' => $piano->getKey(),
                    'relationship' => InstrumentRelationship::Both->value,
                    'proficiency' => ProficiencyLevel::Advanced->value,
                    'is_primary' => true,
                    'years_experience' => 5,
                ]],
                'tag_ids' => [$vip->getKey()],
                'custom_field_inputs' => [
                    $pronunciation->getKey() => 'MY-ah',
                ],
                'status' => PersonStatus::Active->value,
                'source' => 'Website',
                'external_reference' => 'legacy-42',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified()
            ->assertRedirect();

        $person = Person::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('maya@example.test', $person->email);
        $this->assertSame('es', $person->preferred_locale);
        $this->assertSame(StudentStatus::Lead, $person->studentProfile->status);
        $this->assertSame([StaffRole::Teacher->value, StaffRole::Substitute->value], $person->staffProfile->roles);
        $this->assertDatabaseHas('person_instruments', [
            'person_id' => $person->getKey(),
            'instrument_id' => $piano->getKey(),
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('person_tags', [
            'person_id' => $person->getKey(),
            'tag_id' => $vip->getKey(),
        ]);
        $this->assertDatabaseHas('custom_field_values', [
            'person_id' => $person->getKey(),
            'definition_id' => $pronunciation->getKey(),
        ]);
    }

    public function test_edit_uses_optimistic_version_and_does_not_mutate_student_status(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create(['version' => 3]);
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'status' => StudentStatus::Lead,
            'learning_preferences' => [],
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(EditPerson::class, ['record' => $person->getKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet([
                'version' => 3,
                'is_student' => true,
            ])
            ->fillForm([
                'first_name' => 'Updated',
                'student' => [
                    'status' => StudentStatus::Active->value,
                    'school_grade' => '8',
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('Updated', $person->refresh()->first_name);
        $this->assertSame(4, $person->version);
        $this->assertSame(StudentStatus::Lead, $person->studentProfile->refresh()->status);
        $this->assertSame('8', $person->studentProfile->school_grade);
        $this->assertDatabaseCount('student_status_transitions', 0);
    }

    public function test_edit_reports_stale_version_without_overwriting_person(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create([
            'first_name' => 'Original',
            'version' => 1,
        ]);
        $this->filamentAs($owner, $studio);
        $component = Livewire::test(EditPerson::class, ['record' => $person->getKey()]);
        Person::query()->whereKey($person->getKey())->update([
            'first_name' => 'Changed elsewhere',
            'version' => 2,
        ]);

        $component
            ->set('data.first_name', 'Stale browser change')
            ->call('save')
            ->assertHasFormErrors(['version']);

        $person->refresh();
        $this->assertSame('Changed elsewhere', $person->first_name);
        $this->assertSame(2, $person->version);
    }

    public function test_unrelated_edit_preserves_and_labels_inactive_assignments(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create(['first_name' => 'Morgan']);
        $instrument = Instrument::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Retired cello',
            'normalized_name' => 'retired cello',
            'active' => false,
        ]);
        $tag = Tag::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Legacy cohort',
            'normalized_name' => 'legacy cohort',
            'active' => false,
        ]);
        PersonInstrument::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'instrument_id' => $instrument->getKey(),
            'relationship' => InstrumentRelationship::Studies,
            'proficiency' => ProficiencyLevel::Intermediate,
            'is_primary' => true,
        ]);
        PersonTag::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'tag_id' => $tag->getKey(),
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(EditPerson::class, ['record' => $person->getKey()])
            ->assertSuccessful()
            ->assertSee('Retired cello (inactive)')
            ->assertSee('Legacy cohort (inactive)')
            ->set('data.first_name', 'Morgan updated')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Morgan updated', $person->refresh()->first_name);
        $this->assertDatabaseHas('person_instruments', [
            'person_id' => $person->getKey(),
            'instrument_id' => $instrument->getKey(),
        ]);
        $this->assertDatabaseHas('person_tags', [
            'person_id' => $person->getKey(),
            'tag_id' => $tag->getKey(),
        ]);
    }

    public function test_student_status_action_enforces_transition_graph_and_records_history(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create();
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'status' => StudentStatus::Lead,
            'learning_preferences' => [],
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(ViewPerson::class, ['record' => $person->getKey()])
            ->callAction('transitionStudentStatus', data: [
                'status' => StudentStatus::Active->value,
                'reason' => 'Enrolled after assessment',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(StudentStatus::Active, $person->studentProfile->refresh()->status);
        $this->assertNotNull($person->studentProfile->joined_on);
        $this->assertDatabaseHas('student_status_transitions', [
            'person_id' => $person->getKey(),
            'actor_id' => $owner->getKey(),
            'previous_status' => StudentStatus::Lead->value,
            'new_status' => StudentStatus::Active->value,
            'reason' => 'Enrolled after assessment',
        ]);
    }

    public function test_student_status_action_reports_stale_version_without_transitioning(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create(['version' => 1]);
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'status' => StudentStatus::Lead,
            'learning_preferences' => [],
        ]);
        $this->filamentAs($owner, $studio);
        $component = Livewire::test(ViewPerson::class, ['record' => $person->getKey()])
            ->mountAction('transitionStudentStatus')
            ->assertActionDataSet(['version' => 1]);
        Person::query()->whereKey($person->getKey())->update(['version' => 2]);

        $component
            ->setActionData([
                'status' => StudentStatus::Active->value,
                'reason' => 'Stale browser change',
            ])
            ->callMountedAction()
            ->assertNotified('Student status was not changed');

        $this->assertSame(StudentStatus::Lead, $person->studentProfile->refresh()->status);
        $this->assertDatabaseCount('student_status_transitions', 0);
    }

    public function test_billing_member_sees_only_payer_contact_and_no_private_sections(): void
    {
        [$billing, $studio] = $this->member(MembershipRole::Billing);
        $household = Household::factory()->for($studio)->create();
        $payer = Person::factory()->for($studio)->create([
            'first_name' => 'Payer',
            'email' => 'payer@example.test',
            'phone' => '+15550101',
            'pronouns' => 'private pronouns',
        ]);
        $nonPayer = Person::factory()->for($studio)->create([
            'first_name' => 'Private',
            'email' => 'private@example.test',
            'phone' => '+15550102',
        ]);
        foreach ([[$payer, true], [$nonPayer, false]] as [$person, $receivesBilling]) {
            HouseholdMember::query()->create([
                'studio_id' => $studio->getKey(),
                'household_id' => $household->getKey(),
                'person_id' => $person->getKey(),
                'role' => 'other',
                'is_primary_contact' => $receivesBilling,
                'receives_billing' => $receivesBilling,
            ]);
        }
        StudentProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $payer->getKey(),
            'status' => StudentStatus::Active,
            'school_grade' => 'Private grade',
            'lead_source' => 'Private source',
            'learning_preferences' => ['private preference'],
        ]);
        StaffProfile::query()->create([
            'studio_id' => $studio->getKey(),
            'person_id' => $payer->getKey(),
            'roles' => [StaffRole::Teacher->value],
            'status' => StaffStatus::Active,
            'employment_type' => EmploymentType::Contractor,
            'bio' => 'Private biography',
            'can_substitute' => false,
        ]);
        $this->filamentAs($billing, $studio);

        Livewire::test(ListPeople::class)
            ->assertCanSeeTableRecords([$payer, $nonPayer])
            ->assertTableColumnStateSet('email', 'payer@example.test', $payer)
            ->assertTableColumnStateSet('email', null, $nonPayer)
            ->assertActionHidden(TestAction::make('create'));

        Livewire::test(ViewPerson::class, ['record' => $payer->getKey()])
            ->assertSuccessful()
            ->assertSee('payer@example.test')
            ->assertSee($household->name)
            ->assertDontSee('Private grade')
            ->assertDontSee('Private source')
            ->assertDontSee('Private biography')
            ->assertDontSee('private pronouns')
            ->assertActionHidden('edit')
            ->assertActionHidden('transitionStudentStatus');

        Livewire::test(ViewPerson::class, ['record' => $nonPayer->getKey()])
            ->assertSuccessful()
            ->assertDontSee('private@example.test')
            ->assertDontSee($household->name);
    }

    public function test_bulk_archive_and_tag_actions_are_policy_authorized_and_tenant_safe(): void
    {
        [$office, $studio] = $this->member(MembershipRole::Office);
        $otherStudio = Studio::factory()->create();
        $first = Person::factory()->for($studio)->create();
        $second = Person::factory()->for($studio)->create();
        $other = Person::factory()->for($otherStudio)->create();
        $tag = Tag::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Audition',
            'normalized_name' => 'audition',
            'active' => true,
        ]);
        $this->filamentAs($office, $studio);

        Livewire::test(ListPeople::class)
            ->selectTableRecords([$first, $second])
            ->callAction(TestAction::make('addTags')->table()->bulk(), data: [
                'tag_ids' => [$tag->getKey()],
            ])
            ->assertHasNoActionErrors()
            ->selectTableRecords([$first, $second])
            ->callAction(TestAction::make('archive')->table()->bulk())
            ->assertHasNoActionErrors();

        $this->assertSame(PersonStatus::Archived, $first->refresh()->status);
        $this->assertSame(PersonStatus::Archived, $second->refresh()->status);
        $this->assertSame(PersonStatus::Active, $other->refresh()->status);
        $this->assertDatabaseCount('person_tags', 2);
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
