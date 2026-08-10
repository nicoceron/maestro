<?php

namespace Tests\Feature\Api;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Notifications\StudioInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioInvitationApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000')
            ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_owner_can_create_list_and_revoke_a_normalized_invitation_without_exposing_token(): void
    {
        Notification::fake();
        $rawToken = null;
        $sentNotification = null;
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => '  NEW.Teacher@Example.COM ',
            'role' => MembershipRole::Teacher->value,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new.teacher@example.com')
            ->assertJsonPath('data.role', 'teacher')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $invitation = StudioInvitation::query()->sole();
        $this->assertSame('new.teacher@example.com', $invitation->email_normalized);
        $this->assertSame($studio->getKey().'|new.teacher@example.com', $invitation->pending_key);

        Notification::assertSentOnDemand(
            StudioInvitationNotification::class,
            function (StudioInvitationNotification $notification, array $channels, object $notifiable) use (&$rawToken, &$sentNotification): bool {
                $actionUrl = $notification->toMail($notifiable)->actionUrl;
                parse_str((string) parse_url($actionUrl, PHP_URL_FRAGMENT), $fragment);
                $rawToken = $fragment['invite'] ?? null;
                $sentNotification = $notification;

                return $notifiable->routes['mail'] === 'new.teacher@example.com'
                    && is_string($rawToken)
                    && $actionUrl === 'http://localhost:3000/register#invite='.rawurlencode($rawToken);
            },
        );
        $this->assertIsString($rawToken);
        $this->assertInstanceOf(StudioInvitationNotification::class, $sentNotification);
        $this->assertTrue($sentNotification->shouldSend((object) [], 'mail'));
        $this->assertSame(hash('sha256', $rawToken), $invitation->token_hash);
        $this->assertStringNotContainsString(
            $rawToken,
            json_encode($invitation->getAttributes(), JSON_THROW_ON_ERROR),
        );
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
        $this->assertArrayNotHasKey('pending_key', $invitation->toArray());

        $this->getJson("/api/v1/studios/{$studio->slug}/invitations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $response->json('data.id'));

        $this->deleteJson("/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}")
            ->assertNoContent();
        $this->assertSame('revoked', $invitation->refresh()->status());
        $this->assertFalse($sentNotification->shouldSend((object) [], 'mail'));

        $this->deleteJson("/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}")
            ->assertNoContent();
    }

    public function test_role_rules_prevent_owner_transfer_and_administrator_escalation(): void
    {
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'owner@example.com',
            'role' => MembershipRole::Owner->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        [$administrator, $adminStudio] = $this->manager(MembershipRole::Administrator);
        Sanctum::actingAs($administrator);

        $this->postJson("/api/v1/studios/{$adminStudio->slug}/invitations", [
            'email' => 'administrator@example.com',
            'role' => MembershipRole::Administrator->value,
        ])->assertForbidden();

        $this->postJson("/api/v1/studios/{$adminStudio->slug}/invitations", [
            'email' => 'teacher@example.com',
            'role' => MembershipRole::Teacher->value,
        ])->assertCreated();

        [$teacher, $teacherStudio] = $this->manager(MembershipRole::Teacher);
        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/studios/{$teacherStudio->slug}/invitations")->assertForbidden();
    }

    public function test_queued_invitation_recheck_suppresses_expired_and_accepted_messages(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        $token = $this->createInvitation($studio, $owner, 'queued@example.com');
        $invitation = StudioInvitation::query()->sole();
        $notification = new StudioInvitationNotification($invitation->getKey(), $token);

        $this->assertTrue($notification->shouldSend((object) [], 'mail'));

        $invitation->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->assertFalse($notification->shouldSend((object) [], 'mail'));

        $invitation->forceFill([
            'expires_at' => now()->addDay(),
            'accepted_at' => now(),
            'pending_key' => null,
        ])->save();
        $this->assertFalse($notification->shouldSend((object) [], 'mail'));
    }

    public function test_duplicate_pending_and_existing_member_invitations_are_rejected(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        $member = User::factory()->create(['email' => 'member@example.com']);
        $this->membership($member, $studio, MembershipRole::Teacher);
        Sanctum::actingAs($owner);

        $payload = ['email' => 'pending@example.com', 'role' => 'teacher'];
        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", $payload)->assertCreated();
        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'MEMBER@example.com',
            'role' => 'teacher',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_public_preview_never_leaks_the_studio_and_matching_user_acceptance_is_idempotent(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
        $token = $this->createInvitation($studio, $owner, $invitee->email);

        $preview = $this->postJson('/api/v1/invitations/preview', [
            'invitation_token' => $token,
        ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.studio')
            ->assertJsonMissingPath('data.role');
        $this->assertStringNotContainsString($studio->name, $preview->getContent());
        $this->assertStringNotContainsString((string) $studio->getKey(), $preview->getContent());

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.slug', $studio->slug)
            ->assertJsonPath('data.membership.role', 'teacher');
        $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $token])->assertOk();

        $this->assertDatabaseCount('studio_memberships', 2);
        $this->assertNotNull($invitee->refresh()->email_verified_at);
        $this->assertNotNull(StudioInvitation::query()->sole()->accepted_at);
        $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $token])->assertNotFound();
        $this->getJson('/api/v1/invitations/'.$token)->assertNotFound();
        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertNotFound();
    }

    public function test_mismatched_user_expired_and_revoked_tokens_fail_generically_without_membership(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        $token = $this->createInvitation($studio, $owner, 'right@example.com');
        $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
        Sanctum::actingAs($wrongUser);

        $mismatch = $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation_token');
        $this->assertStringNotContainsString($studio->name, $mismatch->getContent());

        $invitation = StudioInvitation::query()->sole();
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();
        $rightUser = User::factory()->create(['email' => 'right@example.com']);
        Sanctum::actingAs($rightUser);
        $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('invitation_token');

        $revokedToken = $this->createInvitation($studio, $owner, 'revoked@example.com');
        $revoked = StudioInvitation::query()->where('email_normalized', 'revoked@example.com')->sole();
        $revoked->forceFill(['revoked_at' => now(), 'pending_key' => null])->save();
        Sanctum::actingAs(User::factory()->create(['email' => 'revoked@example.com']));
        $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $revokedToken])
            ->assertUnprocessable()->assertJsonValidationErrors('invitation_token');

        $this->assertDatabaseMissing('studio_memberships', ['user_id' => $rightUser->getKey()]);
    }

    public function test_invited_registration_only_validates_the_invitation_until_explicit_onboarding(): void
    {
        Notification::fake();
        [$owner, $studio] = $this->manager(MembershipRole::Owner);
        $token = $this->createInvitation($studio, $owner, 'new.user@example.com');
        Auth::forgetGuards();

        $wrongInviteResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Wrong Invitee',
            'email' => 'wrong.invitee@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invitation_token' => $token,
        ])->assertAccepted()->assertExactJson([
            'message' => 'If registration can be completed, check your email for next steps.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'wrong.invitee@example.com']);

        $validInviteResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'NEW.USER@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invitation_token' => $token,
        ])->assertAccepted();
        $this->assertSame($wrongInviteResponse->getContent(), $validInviteResponse->getContent());
        $this->assertSame(
            $wrongInviteResponse->headers->get('Location'),
            $validInviteResponse->headers->get('Location'),
        );

        $user = User::query()->where('email', 'new.user@example.com')->sole();
        $this->assertGuest('web');
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseMissing('studio_memberships', [
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
        ]);
        $this->assertNull(StudioInvitation::query()->sole()->accepted_at);

        $password = $user->password;
        $existingInviteResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Replacement User',
            'email' => 'new.user@example.com',
            'password' => 'Replacement-Password-84!',
            'password_confirmation' => 'Replacement-Password-84!',
            'invitation_token' => $token,
        ])->assertAccepted();
        $this->assertSame($validInviteResponse->getContent(), $existingInviteResponse->getContent());
        $this->assertSame($password, $user->refresh()->password);
        $this->assertSame('New User', $user->name);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();
        $sessionCookie = $login->getCookie((string) config('session.cookie'));
        $this->assertNotNull($sessionCookie);
        $this->withCredentials()->withCookie(
            (string) config('session.cookie'),
            $sessionCookie->getValue(),
        );
        Auth::forgetGuards();

        $this->postJson('/api/v1/onboarding', ['invitation_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.membership.role', MembershipRole::Teacher->value);
        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertDatabaseHas('studio_memberships', [
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => MembershipRole::Teacher->value,
            'status' => MembershipStatus::Active->value,
        ]);

        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $this->defaultCookies = [];
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Replay User',
            'email' => 'replay@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'invitation_token' => $token,
        ])->assertAccepted();
        $this->assertDatabaseMissing('users', ['email' => 'replay@example.com']);
    }

    public function test_invitation_management_is_tenant_scoped(): void
    {
        Notification::fake();
        [$firstOwner, $firstStudio] = $this->manager(MembershipRole::Owner);
        [, $secondStudio] = $this->manager(MembershipRole::Owner);
        $this->createInvitation($secondStudio, User::factory()->create(), 'second@example.com');
        Sanctum::actingAs($firstOwner);

        $this->getJson("/api/v1/studios/{$secondStudio->slug}/invitations")->assertForbidden();
        $invitation = StudioInvitation::query()->sole();
        $this->deleteJson("/api/v1/studios/{$firstStudio->slug}/invitations/{$invitation->getKey()}")
            ->assertNotFound();
    }

    private function createInvitation(Studio $studio, User $inviter, string $email): string
    {
        $result = app(CreateStudioInvitation::class)->handle(
            $studio,
            $inviter,
            $email,
            MembershipRole::Teacher,
        );

        return $result['token'];
    }

    /** @return array{User, Studio} */
    private function manager(MembershipRole $role): array
    {
        $user = User::factory()->create();
        $studio = Studio::factory()->create();
        $this->membership($user, $studio, $role);

        return [$user, $studio];
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
