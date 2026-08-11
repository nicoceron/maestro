<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Models\Household;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HouseholdResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_table_is_scoped_to_the_active_filament_studio(): void
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        $otherStudio = Studio::factory()->create();
        $ownHouseholds = Household::factory()->for($studio)->count(2)->create();
        $otherHousehold = Household::factory()->for($otherStudio)->create();
        $this->membership($user, $studio, MembershipRole::Office);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        Livewire::test(ListHouseholds::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($ownHouseholds)
            ->assertCanNotSeeTableRecords([$otherHousehold]);
    }

    public function test_family_resource_has_no_unsafe_empty_record_create_flow(): void
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        $this->membership($user, $studio, MembershipRole::Owner);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        $this->assertFalse(HouseholdResource::canCreate());
    }

    private function membership(User $user, Studio $studio, MembershipRole $role): StudioMembership
    {
        return StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
    }
}
