<?php

namespace Tests\Feature\Filament;

use App\DataLifecycle\Filament\Pages\TenantDataLifecycle;
use App\Enums\MembershipRole;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class TenantDataLifecyclePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32))]);
    }

    public function test_owner_sees_scoped_data_lifecycle_workspace_and_teacher_is_denied(): void
    {
        $studio = Studio::factory()->create();
        $owner = User::factory()->create();
        $ownerMembership = StudioMembership::factory()->owner()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $owner->getKey(),
        ]);
        $this->filamentAs($owner, $studio, $ownerMembership);

        $this->assertTrue(TenantDataLifecycle::canAccess());
        Livewire::test(TenantDataLifecycle::class)
            ->assertSuccessful()
            ->assertActionVisible('requestExport')
            ->assertActionVisible('retention')
            ->assertActionVisible('requestDeletion')
            ->assertSee('Portable exports')
            ->assertSee('Automation never performs a raw hard delete.');

        $teacher = User::factory()->create();
        $teacherMembership = StudioMembership::factory()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $teacher->getKey(),
            'role' => MembershipRole::Teacher,
        ]);
        $this->filamentAs($teacher, $studio, $teacherMembership);
        $this->assertFalse(TenantDataLifecycle::canAccess());
    }

    private function filamentAs(User $user, Studio $studio, StudioMembership $membership): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        app(TenantContext::class)->activate($studio, $membership);
    }
}
