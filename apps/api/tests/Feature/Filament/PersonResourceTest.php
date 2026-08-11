<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PersonResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_directory_is_tenant_scoped(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $otherStudio = Studio::factory()->create();
        $visible = Person::factory()->for($studio)->create([
            'first_name' => 'Visible',
            'last_name' => 'Student',
        ]);
        $hidden = Person::factory()->for($otherStudio)->create([
            'first_name' => 'Hidden',
            'last_name' => 'Student',
        ]);

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        Livewire::test(ListPeople::class)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_owner_can_add_a_lead_student_through_filament(): void
    {
        [$owner, $studio] = $this->member(MembershipRole::Owner);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        Livewire::test(ListPeople::class)
            ->callAction('addStudent', data: [
                'first_name' => 'Maya',
                'last_name' => 'Rivera',
                'preferred_name' => 'May',
                'email' => 'MAYA@example.test',
                'phone' => '+1 555 0100',
                'birth_date' => '2014-06-15',
                'student_status' => StudentStatus::Lead->value,
                'school_grade' => '6',
            ])
            ->assertHasNoActionErrors();

        $person = Person::query()->where('studio_id', $studio->getKey())->sole();
        $this->assertSame('Maya', $person->first_name);
        $this->assertSame('maya@example.test', $person->email);
        $this->assertDatabaseHas('student_profiles', [
            'studio_id' => $studio->getKey(),
            'person_id' => $person->getKey(),
            'status' => StudentStatus::Lead->value,
            'school_grade' => '6',
        ]);

        $blankEmail = Person::factory()->for($studio)->create(['email' => " \u{00A0} "]);
        $this->assertNull($blankEmail->email);
    }

    public function test_billing_member_can_view_but_cannot_add_people(): void
    {
        [$billing, $studio] = $this->member(MembershipRole::Billing);
        $person = Person::factory()->for($studio)->create();
        $this->actingAs($billing);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        Livewire::test(ListPeople::class)
            ->assertCanSeeTableRecords([$person])
            ->assertActionHidden('addStudent');
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
