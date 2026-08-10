<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_members_can_access_only_active_managed_studios(): void
    {
        $owner = User::factory()->create();
        $first = Studio::factory()->create(['name' => 'Aria']);
        $second = Studio::factory()->create(['name' => 'Bravo']);
        $suspended = Studio::factory()->create(['name' => 'Closed']);
        $other = Studio::factory()->create(['name' => 'Other']);
        $this->membership($owner, $first, MembershipRole::Owner);
        $this->membership($owner, $second, MembershipRole::Office);
        $this->membership($owner, $suspended, MembershipRole::Administrator, MembershipStatus::Suspended);

        $panel = Filament::getPanel('admin');

        $this->assertTrue($owner->canAccessPanel($panel));
        $this->assertTrue($owner->canAccessTenant($first));
        $this->assertTrue($owner->canAccessTenant($second));
        $this->assertFalse($owner->canAccessTenant($suspended));
        $this->assertFalse($owner->canAccessTenant($other));
        $this->assertSame(['Aria', 'Bravo'], $owner->getTenants($panel)->pluck('name')->all());
    }

    public function test_teacher_members_use_the_next_portal_not_the_management_panel(): void
    {
        $teacher = User::factory()->create();
        $studio = Studio::factory()->create();
        $this->membership($teacher, $studio, MembershipRole::Teacher);

        $panel = Filament::getPanel('admin');

        $this->assertFalse($teacher->canAccessPanel($panel));
        $this->assertFalse($teacher->canAccessTenant($studio));
        $this->assertCount(0, $teacher->getTenants($panel));
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
}
