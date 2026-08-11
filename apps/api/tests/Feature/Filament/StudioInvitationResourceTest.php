<?php

namespace Tests\Feature\Filament;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Resources\StudioInvitations\Pages\ListStudioInvitations;
use App\Filament\Resources\StudioInvitations\StudioInvitationResource;
use App\Filament\StudioInvitations\StudioInvitationManager;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Auth\SensitiveRateLimitKey;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class StudioInvitationResourceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42!';

    public function test_invitation_table_is_scoped_to_the_active_filament_studio(): void
    {
        [$owner, $studio] = $this->owner();
        [, $otherStudio] = $this->owner();
        $ownInvitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'own-studio@example.com',
            MembershipRole::Teacher,
        );
        $otherInvitation = app(CreateStudioInvitation::class)->handle(
            $otherStudio,
            User::factory()->create(),
            'other-studio@example.com',
            MembershipRole::Teacher,
        );
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        Livewire::test(ListStudioInvitations::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownInvitation])
            ->assertCanNotSeeTableRecords([$otherInvitation]);
    }

    public function test_owner_can_create_resend_and_revoke_through_guarded_filament_actions(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        $component = Livewire::test(ListStudioInvitations::class)
            ->callAction('invite', data: [
                'email' => 'filament-invite@example.com',
                'role' => MembershipRole::Teacher->value,
                'current_password' => self::PASSWORD,
            ])
            ->assertHasNoActionErrors();

        $invitation = StudioInvitation::query()
            ->where('studio_id', $studio->getKey())
            ->where('email_normalized', 'filament-invite@example.com')
            ->sole();
        $this->assertTrue($invitation->isPending());

        $this->travel(61)->seconds();
        $component->callAction(
            TestAction::make('resend')->table($invitation),
            data: ['current_password' => self::PASSWORD],
        )->assertHasNoActionErrors();

        $replacement = StudioInvitation::query()
            ->where('previous_invitation_id', $invitation->getKey())
            ->sole();
        $this->assertSame('superseded', $invitation->refresh()->status());

        $component->callAction(
            TestAction::make('revoke')->table($replacement),
            data: ['current_password' => self::PASSWORD],
        )->assertHasNoActionErrors();
        $this->assertSame('revoked', $replacement->refresh()->status());
    }

    public function test_filament_invitable_roles_and_mutations_follow_the_studio_policy(): void
    {
        Queue::fake();
        [$administrator, $studio] = $this->manager(MembershipRole::Administrator);
        $this->actingAs($administrator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        $roles = StudioInvitationResource::invitableRoleLabels();
        $this->assertArrayNotHasKey(MembershipRole::Administrator->value, $roles);
        $this->assertArrayHasKey(MembershipRole::Teacher->value, $roles);

        try {
            app(StudioInvitationManager::class)->create(
                $studio,
                $administrator,
                'forbidden-role@example.com',
                MembershipRole::Administrator,
                self::PASSWORD,
            );
            $this->fail('Administrator role escalation was not rejected.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('studio_invitations', 0);
        Queue::assertNothingPushed();
    }

    public function test_filament_create_uses_the_shared_sensitive_quota_without_side_effects(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);
        $key = md5('invitation-create'.app(SensitiveRateLimitKey::class)->for(
            'invitation-create-inviter',
            $owner->getAuthIdentifier(),
        ));

        for ($attempt = 0; $attempt < 20; $attempt++) {
            RateLimiter::hit($key, 3600);
        }

        Livewire::test(ListStudioInvitations::class)
            ->callAction('invite', data: [
                'email' => 'filament-limited@example.com',
                'role' => MembershipRole::Teacher->value,
                'current_password' => self::PASSWORD,
            ])
            ->assertNotified('Invitation limit reached');

        $this->assertDatabaseCount('studio_invitations', 0);
        $this->assertDatabaseCount('studio_invitation_deliveries', 0);
        $this->assertDatabaseCount('studio_audit_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_filament_invitation_actions_reject_wrong_password_without_side_effects(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($studio);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            Livewire::test(ListStudioInvitations::class)
                ->callAction('invite', data: [
                    'email' => 'wrong-password@example.com',
                    'role' => MembershipRole::Teacher->value,
                    'current_password' => 'not-the-current-password',
                ])
                ->assertHasActionErrors(['current_password']);
        }

        $keys = app(SensitiveRateLimitKey::class);
        $this->assertSame(5, (int) RateLimiter::attempts($keys->for(
            'filament-identity-confirmation-account',
            $owner->getAuthIdentifier(),
        )));
        $this->assertSame(5, (int) RateLimiter::attempts($keys->for(
            'filament-identity-confirmation-ip',
            '127.0.0.1',
        )));

        $this->assertDatabaseCount('studio_invitations', 0);
        $this->assertDatabaseCount('studio_invitation_deliveries', 0);
        $this->assertDatabaseCount('studio_audit_events', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{User, Studio} */
    private function owner(): array
    {
        return $this->manager(MembershipRole::Owner);
    }

    /** @return array{User, Studio} */
    private function manager(MembershipRole $role): array
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
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
