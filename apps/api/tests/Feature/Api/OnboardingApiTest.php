<?php

namespace Tests\Feature\Api;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class OnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_requires_authentication_and_new_studio_requires_verified_email(): void
    {
        $payload = $this->studioPayload();

        $this->postJson('/api/v1/onboarding', $payload)->assertUnauthorized();

        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/onboarding', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your email address is not verified.');
        $this->assertDatabaseCount('studios', 0);
        $this->assertDatabaseCount('studio_memberships', 0);
    }

    public function test_new_studio_onboarding_is_replay_safe_and_merges_only_safe_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/v1/onboarding', [
            ...$this->studioPayload('First Studio'),
            'preferred_name' => '  Ari  ',
            'workspace_mode' => 'owner',
            'primary_goal' => 'schedule',
        ])->assertOk()
            ->assertJsonPath('data.name', 'First Studio')
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.membership.status', 'active');

        $studio = Studio::query()->sole();
        $membership = StudioMembership::query()->sole();
        $membership->forceFill([
            'preferences' => [
                ...$membership->preferences,
                'digest_enabled' => true,
            ],
        ])->save();

        $this->postJson('/api/v1/onboarding', [
            ...$this->studioPayload('Duplicate Studio'),
            'preferred_name' => 'Ariana',
        ])->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.name', 'First Studio');

        $this->assertTrue($studio->is(Studio::query()->sole()));
        $this->assertDatabaseCount('studios', 1);
        $this->assertDatabaseCount('studio_memberships', 1);
        $this->assertSame([
            'preferred_name' => 'Ariana',
            'workspace_mode' => 'owner',
            'primary_goal' => 'schedule',
            'digest_enabled' => true,
        ], $membership->refresh()->preferences);
    }

    public function test_invitation_onboarding_verifies_the_matching_user_and_is_idempotent(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->studioOwner();
        $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
        $token = $this->invitationToken($studio, $owner, $invitee->email);
        Sanctum::actingAs($invitee);

        $first = $this->postJson('/api/v1/onboarding', [
            'invitation_token' => $token,
            'preferred_name' => 'Invitee',
            'workspace_mode' => 'teacher',
            'primary_goal' => 'teaching',
        ])->assertOk()
            ->assertJsonPath('data.id', $studio->getKey())
            ->assertJsonPath('data.membership.role', 'teacher');

        $membership = StudioMembership::query()
            ->where('user_id', $invitee->getKey())
            ->sole();
        $membership->forceFill([
            'preferences' => [...$membership->preferences, 'calendar_density' => 'compact'],
        ])->save();

        $this->postJson('/api/v1/onboarding', [
            'invitation_token' => $token,
            'preferred_name' => 'Updated Invitee',
            'primary_goal' => 'growth',
        ])->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertNotNull($invitee->refresh()->email_verified_at);
        $this->assertDatabaseCount('studio_memberships', 2);
        $this->assertNotNull(StudioInvitation::query()->sole()->accepted_at);
        $this->assertSame([
            'preferred_name' => 'Updated Invitee',
            'workspace_mode' => 'teacher',
            'primary_goal' => 'growth',
            'calendar_density' => 'compact',
        ], $membership->refresh()->preferences);
    }

    public function test_onboarding_rejects_ambiguous_payloads_invalid_preferences_and_client_roles(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/onboarding', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation_token', 'studio']);

        $this->postJson('/api/v1/onboarding', [
            ...$this->studioPayload(),
            'invitation_token' => str_repeat('a', 64),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['invitation_token', 'studio']);

        $this->postJson('/api/v1/onboarding', [
            ...$this->studioPayload(),
            'workspace_mode' => 'super-admin',
            'primary_goal' => 'impersonation',
            'role' => 'owner',
            'studio' => [
                ...$this->studioPayload()['studio'],
                'role' => 'owner',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['workspace_mode', 'primary_goal', 'role', 'studio.role']);

        $this->assertDatabaseCount('studios', 0);
        $this->assertDatabaseCount('studio_memberships', 0);
    }

    public function test_invitation_onboarding_rolls_back_acceptance_when_membership_creation_fails(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->studioOwner();
        $invitee = User::factory()->unverified()->create(['email' => 'rollback@example.com']);
        $token = $this->invitationToken($studio, $owner, $invitee->email);
        $invitation = StudioInvitation::query()->sole();
        Sanctum::actingAs($invitee);

        $originalDispatcher = StudioMembership::getEventDispatcher();
        $isolatedDispatcher = clone $originalDispatcher;
        StudioMembership::setEventDispatcher($isolatedDispatcher);
        StudioMembership::created(
            function (StudioMembership $membership) use ($invitee): void {
                if ($membership->user_id === $invitee->getKey()) {
                    throw new RuntimeException('Simulated membership failure.');
                }
            },
        );

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/onboarding', ['invitation_token' => $token]);
            $this->fail('The simulated membership failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated membership failure.', $exception->getMessage());
        } finally {
            StudioMembership::setEventDispatcher($originalDispatcher);
        }

        $this->assertDatabaseMissing('studio_memberships', ['user_id' => $invitee->getKey()]);
        $this->assertNull($invitation->refresh()->accepted_at);
        $this->assertNotNull($invitation->pending_key);
        $this->assertNull($invitee->refresh()->email_verified_at);
    }

    /** @return array{studio: array{name: string, timezone: string, locale: string, currency: string, week_starts_on: int}} */
    private function studioPayload(string $name = 'New Studio'): array
    {
        return [
            'studio' => [
                'name' => $name,
                'timezone' => 'America/Bogota',
                'locale' => 'es',
                'currency' => 'cop',
                'week_starts_on' => 1,
            ],
        ];
    }

    /** @return array{User, Studio} */
    private function studioOwner(): array
    {
        $owner = User::factory()->create();
        $studio = Studio::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $owner->getKey(),
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);

        return [$owner, $studio];
    }

    private function invitationToken(Studio $studio, User $owner, string $email): string
    {
        $result = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            $email,
            MembershipRole::Teacher,
        );

        return $result['token'];
    }
}
