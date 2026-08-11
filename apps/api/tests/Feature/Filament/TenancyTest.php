<?php

namespace Tests\Feature\Filament;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42!';

    public function test_active_members_can_access_only_their_active_studios(): void
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

    public function test_teacher_members_use_the_authenticated_filament_application(): void
    {
        $teacher = User::factory()->create(['password' => self::PASSWORD]);
        $studio = Studio::factory()->create();
        $this->membership($teacher, $studio, MembershipRole::Teacher);

        $panel = Filament::getPanel('admin');

        $this->assertTrue($teacher->canAccessPanel($panel));
        $this->assertTrue($teacher->canAccessTenant($studio));
        $this->assertSame([$studio->getKey()], $teacher->getTenants($panel)->modelKeys());

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $teacher->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', false);
        $sessionCookie = $response->getCookie((string) config('session.cookie'));
        $this->assertNotNull($sessionCookie);
        $this->withCookie((string) config('session.cookie'), $sessionCookie->getValue());
        Auth::forgetGuards();

        $this->get("/manage/studio/{$studio->slug}")->assertOk();
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
