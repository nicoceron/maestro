<?php

namespace Tests\Feature\Filament;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Resources\CustomFields\CustomFieldDefinitionResource;
use App\Filament\Resources\CustomFields\Pages\ManageCustomFieldDefinitions;
use App\Filament\Resources\Instruments\InstrumentResource;
use App\Filament\Resources\Instruments\Pages\ManageInstruments;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PeopleConfigurationResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_instrument_configuration_is_tenant_scoped_normalized_and_deactivatable(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $otherStudio = Studio::factory()->create();
        $other = Instrument::query()->create([
            'studio_id' => $otherStudio->getKey(),
            'name' => 'Cello',
            'normalized_name' => 'cello',
            'active' => true,
        ]);
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(ManageInstruments::class)
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$other])
            ->callAction('create', data: [
                'name' => '  Grand   Piano  ',
                'active' => true,
            ])
            ->assertHasNoActionErrors();

        $instrument = Instrument::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('grand piano', $instrument->normalized_name);

        $component->callAction(
            TestAction::make('toggleAvailability')->table($instrument),
        )->assertHasNoActionErrors();

        $this->assertFalse($instrument->refresh()->active);
        $this->assertFalse(InstrumentResource::canDelete($instrument));
    }

    public function test_tag_configuration_enforces_scoped_case_insensitive_uniqueness(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        Tag::query()->create([
            'studio_id' => $studio->getKey(),
            'name' => 'Audition',
            'normalized_name' => 'audition',
            'active' => true,
        ]);
        $this->filamentAs($owner, $studio);

        Livewire::test(ManageTags::class)
            ->callAction('create', data: [
                'name' => ' AUDITION ',
                'active' => true,
            ])
            ->assertHasActionErrors(['name']);

        $this->assertDatabaseCount('tags', 1);
    }

    public function test_custom_fields_validate_options_and_protect_used_configuration(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $person = Person::factory()->for($studio)->create();
        $this->filamentAs($owner, $studio);

        $component = Livewire::test(ManageCustomFieldDefinitions::class)
            ->callAction('create', data: [
                'name' => 'Program',
                'key' => 'program',
                'type' => CustomFieldType::Select->value,
                'applies_to' => CustomFieldAppliesTo::Person->value,
                'options' => ['Classical', 'Jazz'],
                'required' => false,
                'active' => true,
                'sort_order' => 1,
            ])
            ->assertHasNoActionErrors();

        $definition = CustomFieldDefinition::query()->where('studio_id', $studio->getKey())->sole();
        CustomFieldValue::query()->create([
            'studio_id' => $studio->getKey(),
            'definition_id' => $definition->getKey(),
            'person_id' => $person->getKey(),
            'value' => ['value' => 'Jazz'],
        ]);

        $component
            ->mountAction(TestAction::make('edit')->table($definition))
            ->assertFormFieldDisabled('type')
            ->assertFormFieldDisabled('applies_to')
            ->assertFormFieldDisabled('options');

        try {
            CustomFieldDefinitionResource::assertSafeChange($definition, [
                'type' => CustomFieldType::Select->value,
                'applies_to' => CustomFieldAppliesTo::Person->value,
                'options' => ['Classical'],
            ]);
            $this->fail('A used custom field accepted an option shape change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }

        $definition->refresh();
        $this->assertSame(CustomFieldType::Select, $definition->type);
        $this->assertSame(CustomFieldAppliesTo::Person, $definition->applies_to);
        $this->assertSame(['Classical', 'Jazz'], $definition->options);
    }

    public function test_custom_fields_reject_case_insensitive_duplicate_and_unbounded_options(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->filamentAs($owner, $studio);

        Livewire::test(ManageCustomFieldDefinitions::class)
            ->callAction('create', data: [
                'name' => 'Lesson format',
                'key' => 'lesson_format',
                'type' => CustomFieldType::Select->value,
                'applies_to' => CustomFieldAppliesTo::Person->value,
                'options' => ['Online', 'online'],
                'required' => false,
                'active' => true,
                'sort_order' => 0,
            ])
            ->assertHasActionErrors(['options']);

        Livewire::test(ManageCustomFieldDefinitions::class)
            ->callAction('create', data: [
                'name' => 'Oversized catalog',
                'key' => 'oversized_catalog',
                'type' => CustomFieldType::Select->value,
                'applies_to' => CustomFieldAppliesTo::Person->value,
                'options' => array_map(fn (int $index): string => "Option {$index}", range(1, 101)),
                'required' => false,
                'active' => true,
                'sort_order' => 0,
            ])
            ->assertHasActionErrors(['options']);

        $this->assertDatabaseEmpty('custom_field_definitions');
    }

    public function test_billing_member_cannot_view_or_mutate_people_configuration(): void
    {
        [$billing, $studio] = $this->member(MembershipRole::Billing);
        $this->filamentAs($billing, $studio);

        $this->assertFalse(InstrumentResource::canViewAny());
        $this->assertFalse(TagResource::canViewAny());
        $this->assertFalse(CustomFieldDefinitionResource::canViewAny());
        $this->assertFalse(InstrumentResource::canCreate());
        $this->assertFalse(TagResource::canCreate());
        $this->assertFalse(CustomFieldDefinitionResource::canCreate());
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
