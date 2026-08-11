<?php

namespace Tests\Feature\Api;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Actions\Invitations\ResendStudioInvitation;
use App\Actions\Invitations\RevokeStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Exceptions\InvitationDeliveryFailed;
use App\Jobs\DeliverStudioInvitation;
use App\Models\Studio;
use App\Models\StudioAuditEvent;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Models\StudioMembership;
use App\Models\User;
use App\Notifications\StudioInvitationNotification;
use App\Support\Auth\InvitationToken;
use App\Support\Auth\SensitiveRateLimitKey;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

final class InvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:3000')
            ->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    }

    public function test_delivery_job_and_notification_payloads_are_token_safe_idempotent_and_audited(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        Sanctum::actingAs($owner);
        $untrustedRequestId = str_repeat('A', 43);

        $this->withHeader('X-Request-ID', $untrustedRequestId)
            ->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'delivery@example.com',
                'role' => 'teacher',
            ])->assertCreated()
            ->assertJsonPath('message', 'Invitation queued.')
            ->assertJsonPath('data.delivery_status', 'pending')
            ->assertJsonPath('data.send_count', 0)
            ->assertJsonMissingPath('data.lineage_id')
            ->assertJsonMissingPath('data.delivery_version')
            ->assertJsonMissingPath('data.previous_invitation_id');

        $invitation = StudioInvitation::query()->sole();
        $token = $this->token($invitation);
        $job = null;
        Queue::assertPushed(DeliverStudioInvitation::class, function (DeliverStudioInvitation $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(DeliverStudioInvitation::class, $job);
        $this->assertTrue($job->afterCommit);

        $serialized = serialize($job);
        $this->assertStringContainsString((string) $invitation->getKey(), $serialized);
        $this->assertStringNotContainsString($token, $serialized);
        $this->assertStringNotContainsString($invitation->email_normalized, $serialized);
        $this->assertStringNotContainsString((string) $studio->getKey(), $serialized);
        $restored = unserialize($serialized);
        $this->assertSame($invitation->getKey(), $restored->invitationId);
        $this->assertSame(1, $restored->deliveryVersion);
        $serializedNotification = serialize(new StudioInvitationNotification(
            $invitation->getKey(),
            $invitation->delivery_version,
        ));
        $this->assertStringNotContainsString($token, $serializedNotification);
        $this->assertStringNotContainsString($invitation->email_normalized, $serializedNotification);
        $this->assertStringNotContainsString((string) $studio->getKey(), $serializedNotification);

        Notification::fake();
        app()->call([$restored, 'handle']);
        app()->call([$restored, 'handle']);

        Notification::assertSentOnDemandTimes(StudioInvitationNotification::class, 1);
        $this->assertDatabaseHas('studio_invitation_deliveries', [
            'invitation_id' => $invitation->getKey(),
            'status' => 'sent',
        ]);
        $this->assertSame(1, $invitation->refresh()->send_count);
        $this->assertDatabaseCount('studio_audit_events', 3);
        $this->assertSame([
            'invitation.created',
            'invitation.delivered',
            'invitation.delivery_queued',
        ], StudioAuditEvent::query()->orderBy('event_type')->pluck('event_type')->all());
        $this->assertSame([
            'invitation.created',
            'invitation.delivery_queued',
            'invitation.delivered',
        ], StudioAuditEvent::query()
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('event_type')
            ->all());
        $this->assertFalse(StudioAuditEvent::query()->where('request_id', $untrustedRequestId)->exists());
        $this->assertFalse(StudioAuditEvent::query()->get()->contains(
            fn (StudioAuditEvent $event): bool => str_contains($event->toJson(), $token),
        ));
    }

    public function test_provider_exception_is_rethrown_without_token_or_exception_chaining(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'provider@example.com',
            MembershipRole::Teacher,
        );
        $token = $this->token($invitation);
        $job = new DeliverStudioInvitation($invitation->getKey(), $invitation->delivery_version);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('sendNow')
            ->once()
            ->andThrow(new RuntimeException('provider echoed '.$token));
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app()->call([$job, 'handle']);
            $this->fail('The provider failure was not surfaced.');
        } catch (InvitationDeliveryFailed $exception) {
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($token, serialize($job));
        }

        $this->assertDatabaseHas('studio_invitation_deliveries', [
            'invitation_id' => $invitation->getKey(),
            'status' => 'pending',
        ]);

        $job->failed(new InvitationDeliveryFailed);

        $this->assertDatabaseHas('studio_invitation_deliveries', [
            'invitation_id' => $invitation->getKey(),
            'status' => 'suppressed',
            'suppression_reason' => 'delivery_failed',
        ]);
        $this->assertDatabaseHas('studio_audit_events', [
            'subject_id' => $invitation->getKey(),
            'event_type' => 'invitation.delivery_suppressed',
        ]);
    }

    public function test_resend_supersedes_atomically_invalidates_old_token_and_exposes_safe_capabilities(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        Sanctum::actingAs($owner);
        $original = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'renew@example.com',
            MembershipRole::Teacher,
        );
        $oldToken = $this->token($original);
        $this->travel(61)->seconds();

        $response = $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$original->getKey()}/resend",
        )->assertAccepted()
            ->assertJsonPath('message', 'Invitation resend queued.')
            ->assertJsonPath('data.email', 'renew@example.com')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.delivery_status', 'pending')
            ->assertJsonPath('data.permissions.can_revoke', true)
            ->assertJsonMissingPath('data.lineage_id')
            ->assertJsonMissingPath('data.delivery_version')
            ->assertJsonMissingPath('data.superseded_by_id');

        $replacement = StudioInvitation::query()->findOrFail($response->json('data.id'));
        $this->assertSame('superseded', $original->refresh()->status());
        $this->assertSame($replacement->getKey(), $original->superseded_by_id);
        $this->assertSame($original->getKey(), $replacement->previous_invitation_id);
        $this->assertSame($original->lineage_id, $replacement->lineage_id);
        $this->assertSame(2, $replacement->delivery_version);
        $this->assertDatabaseHas('studio_invitation_deliveries', [
            'invitation_id' => $original->getKey(),
            'status' => 'suppressed',
            'suppression_reason' => 'superseded',
        ]);
        $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $oldToken])
            ->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['email' => 'renew@example.com']));
        $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $oldToken])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation_token');
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $this->token($replacement)])
            ->assertOk();

        $this->getJson("/api/v1/studios/{$studio->slug}/invitations?status=pending&role=teacher&q=renew")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('capabilities.can_create', true)
            ->assertJsonPath('capabilities.invitable_roles.0', 'administrator')
            ->assertJsonPath('data.0.permissions.can_resend', false);
        $this->getJson("/api/v1/studios/{$studio->slug}/invitations?q=%25")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('studio_audit_events', [
            'subject_id' => $original->getKey(),
            'event_type' => 'invitation.superseded',
        ]);
        $this->assertDatabaseHas('studio_audit_events', [
            'subject_id' => $replacement->getKey(),
            'event_type' => 'invitation.resent',
        ]);
    }

    public function test_resend_has_persistent_three_per_day_quota(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'quota@example.com',
            MembershipRole::Teacher,
        );

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->travel(61)->seconds();
            $invitation = app(ResendStudioInvitation::class)->handle($invitation, $owner);
        }

        $this->travel(61)->seconds();
        $counts = [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ];

        try {
            app(ResendStudioInvitation::class)->handle($invitation, $owner);
            $this->fail('The persistent daily resend quota was not enforced.');
        } catch (TooManyRequestsHttpException $exception) {
            $this->assertSame(86400, $exception->getHeaders()['Retry-After']);
        }

        $this->assertSame($counts, [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ]);
    }

    public function test_rejected_callers_do_not_consume_authorized_create_quotas(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $keys = app(SensitiveRateLimitKey::class);
        $studioQuotaKey = md5('invitation-create'.$keys->for(
            'invitation-create-studio',
            $studio->getRouteKey(),
        ));
        $ownerQuotaKey = md5('invitation-create'.$keys->for(
            'invitation-create-inviter',
            $owner->getAuthIdentifier(),
        ));
        $outsider = User::factory()->create();
        $outsiderQuotaKey = md5('invitation-create'.$keys->for(
            'invitation-create-inviter',
            $outsider->getAuthIdentifier(),
        ));

        Sanctum::actingAs($outsider);
        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'outsider-quota@example.com',
            'role' => MembershipRole::Teacher->value,
        ])->assertForbidden();

        $this->assertSame(0, RateLimiter::attempts($outsiderQuotaKey));
        $this->assertSame(0, RateLimiter::attempts($studioQuotaKey));

        $teacher = User::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $teacher->getKey(),
            'role' => MembershipRole::Teacher,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        $teacherQuotaKey = md5('invitation-create'.$keys->for(
            'invitation-create-inviter',
            $teacher->getAuthIdentifier(),
        ));
        Sanctum::actingAs($teacher);
        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'teacher-role-denied@example.com',
            'role' => MembershipRole::Teacher->value,
        ])->assertForbidden();
        $this->assertSame(0, RateLimiter::attempts($teacherQuotaKey));
        $this->assertSame(0, RateLimiter::attempts($studioQuotaKey));

        $administrator = User::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $administrator->getKey(),
            'role' => MembershipRole::Administrator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        $administratorQuotaKey = md5('invitation-create'.$keys->for(
            'invitation-create-inviter',
            $administrator->getAuthIdentifier(),
        ));
        Sanctum::actingAs($administrator);
        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'administrator-role-denied@example.com',
            'role' => MembershipRole::Administrator->value,
        ])->assertForbidden();
        $this->assertSame(0, RateLimiter::attempts($administratorQuotaKey));
        $this->assertSame(0, RateLimiter::attempts($studioQuotaKey));

        Sanctum::actingAs($owner);
        $this->withSession(['auth.password_confirmed_at' => 0])
            ->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'unconfirmed-quota@example.com',
                'role' => MembershipRole::Teacher->value,
            ])->assertStatus(423);

        $this->assertSame(0, RateLimiter::attempts($ownerQuotaKey));
        $this->assertSame(0, RateLimiter::attempts($studioQuotaKey));

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'invalid-owner-role@example.com',
                'role' => MembershipRole::Owner->value,
            ])->assertUnprocessable();
        $this->assertSame(0, RateLimiter::attempts($ownerQuotaKey));
        $this->assertSame(0, RateLimiter::attempts($studioQuotaKey));

        $this->assertDatabaseCount('studio_invitations', 0);
        $this->assertDatabaseCount('studio_invitation_deliveries', 0);
        $this->assertDatabaseCount('studio_audit_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_rejected_callers_do_not_consume_authorized_resend_quota(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'resend-authorization@example.com',
            MembershipRole::Teacher,
        );
        $counts = [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ];
        $ip = '198.51.100.7';
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $keys = app(SensitiveRateLimitKey::class);
        $outsider = User::factory()->create();
        $outsiderQuotaKey = md5('invitation-resend'.$keys->for(
            'invitation-resend',
            $outsider->getAuthIdentifier(),
            $invitation->getRouteKey(),
            $ip,
        ));

        Sanctum::actingAs($outsider);
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getRouteKey()}/resend",
        )->assertForbidden();
        $this->assertSame(0, RateLimiter::attempts($outsiderQuotaKey));

        $teacher = User::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $teacher->getKey(),
            'role' => MembershipRole::Teacher,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        $teacherQuotaKey = md5('invitation-resend'.$keys->for(
            'invitation-resend',
            $teacher->getAuthIdentifier(),
            $invitation->getRouteKey(),
            $ip,
        ));
        Sanctum::actingAs($teacher);
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getRouteKey()}/resend",
        )->assertForbidden();
        $this->assertSame(0, RateLimiter::attempts($teacherQuotaKey));

        $ownerQuotaKey = md5('invitation-resend'.$keys->for(
            'invitation-resend',
            $owner->getAuthIdentifier(),
            $invitation->getRouteKey(),
            $ip,
        ));
        Sanctum::actingAs($owner);
        $this->withSession(['auth.password_confirmed_at' => 0])
            ->postJson(
                "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getRouteKey()}/resend",
            )->assertStatus(423);
        $this->assertSame(0, RateLimiter::attempts($ownerQuotaKey));

        $this->assertSame($counts, [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ]);
        Queue::assertPushed(DeliverStudioInvitation::class, 1);
    }

    public function test_create_is_limited_to_twenty_per_hour_per_inviter_without_side_effects(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        [, $otherStudio] = $this->owner();
        StudioMembership::query()->create([
            'studio_id' => $otherStudio->getKey(),
            'user_id' => $owner->getKey(),
            'role' => MembershipRole::Administrator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        Sanctum::actingAs($owner);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $targetStudio = $attempt % 2 === 0 ? $otherStudio : $studio;
            $this->postJson("/api/v1/studios/{$targetStudio->slug}/invitations", [
                'email' => "inviter-quota-{$attempt}@example.com",
                'role' => MembershipRole::Teacher->value,
            ])->assertCreated();
        }

        $counts = [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ];

        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'inviter-quota-rejected@example.com',
            'role' => MembershipRole::Teacher->value,
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertSame($counts, [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ]);
        Queue::assertPushed(DeliverStudioInvitation::class, 20);

        $inviterQuotaKey = md5('invitation-create'.app(SensitiveRateLimitKey::class)->for(
            'invitation-create-inviter',
            $owner->getAuthIdentifier(),
        ));
        $this->assertGreaterThan(0, RateLimiter::availableIn($inviterQuotaKey));
        RateLimiter::clear($inviterQuotaKey);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'inviter-quota-reset@example.com',
                'role' => MembershipRole::Teacher->value,
            ])->assertCreated();
        Queue::assertPushed(DeliverStudioInvitation::class, 21);
    }

    public function test_create_is_limited_to_one_hundred_per_day_per_studio_without_side_effects(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $actors = [$owner];

        for ($actorIndex = 2; $actorIndex <= 6; $actorIndex++) {
            $actor = User::factory()->create();
            StudioMembership::query()->create([
                'studio_id' => $studio->getKey(),
                'user_id' => $actor->getKey(),
                'role' => MembershipRole::Administrator,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'preferences' => [],
            ]);
            $actors[] = $actor;
        }

        foreach (array_slice($actors, 0, 5) as $actorIndex => $actor) {
            Sanctum::actingAs($actor);

            for ($attempt = 1; $attempt <= 20; $attempt++) {
                $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                    'email' => "studio-quota-{$actorIndex}-{$attempt}@example.com",
                    'role' => MembershipRole::Teacher->value,
                ])->assertCreated();
            }
        }

        $counts = [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ];
        Sanctum::actingAs($actors[5]);

        $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
            'email' => 'studio-quota-rejected@example.com',
            'role' => MembershipRole::Teacher->value,
        ])->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertSame($counts, [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ]);
        Queue::assertPushed(DeliverStudioInvitation::class, 100);

        $studioQuotaKey = md5('invitation-create'.app(SensitiveRateLimitKey::class)->for(
            'invitation-create-studio',
            $studio->getRouteKey(),
        ));
        $this->assertGreaterThan(0, RateLimiter::availableIn($studioQuotaKey));
        RateLimiter::clear($studioQuotaKey);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'studio-quota-reset@example.com',
                'role' => MembershipRole::Teacher->value,
            ])->assertCreated();
        Queue::assertPushed(DeliverStudioInvitation::class, 101);
    }

    public function test_http_resend_limiter_terminal_matrix_expired_renewal_and_cross_tenant_scope(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        [, $otherStudio] = $this->owner();
        StudioMembership::query()->create([
            'studio_id' => $otherStudio->getKey(),
            'user_id' => $owner->getKey(),
            'role' => MembershipRole::Administrator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        Sanctum::actingAs($owner);
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'matrix@example.com',
            MembershipRole::Teacher,
        );
        $this->travel(61)->seconds();

        $replacementId = $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}/resend",
        )->assertAccepted()->json('data.id');
        $counts = [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ];
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}/resend",
        )->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame($counts, [
            StudioInvitation::query()->count(),
            StudioInvitationDelivery::query()->count(),
            StudioAuditEvent::query()->count(),
        ]);
        Cache::flush();
        $this->postJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}/resend",
        )->assertUnprocessable()->assertJsonValidationErrors('invitation');

        $replacement = StudioInvitation::query()->findOrFail($replacementId);
        $replacement->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->travel(61)->seconds();
        $renewed = app(ResendStudioInvitation::class)->handle($replacement, $owner);
        $this->assertSame('pending', $renewed->status());

        $this->postJson(
            "/api/v1/studios/{$otherStudio->slug}/invitations/{$renewed->getKey()}/resend",
        )->assertNotFound();

        $accepted = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'accepted-terminal@example.com',
            MembershipRole::Teacher,
        );
        $accepted->forceFill(['accepted_at' => now(), 'accepted_by_id' => $owner->getKey(), 'pending_key' => null])->save();
        $revoked = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'revoked-terminal@example.com',
            MembershipRole::Teacher,
        );
        app(RevokeStudioInvitation::class)->handle($revoked, $owner);
        $expired = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'expired-terminal@example.com',
            MembershipRole::Teacher,
        );
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();

        foreach ([$accepted, $invitation] as $terminal) {
            try {
                app(RevokeStudioInvitation::class)->handle($terminal, $owner);
                $this->fail('A terminal invitation was revoked.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('invitation', $exception->errors());
            }
        }
        app(RevokeStudioInvitation::class)->handle($revoked, $owner);
        $this->assertSame('revoked', $revoked->refresh()->status());
        try {
            app(RevokeStudioInvitation::class)->handle($expired, $owner);
            $this->fail('An expired invitation was revoked instead of renewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }

        foreach ([$accepted, $invitation] as $terminal) {
            $this->deleteJson(
                "/api/v1/studios/{$studio->slug}/invitations/{$terminal->getKey()}",
            )->assertUnprocessable()->assertJsonValidationErrors('invitation');
        }
        $this->deleteJson(
            "/api/v1/studios/{$studio->slug}/invitations/{$revoked->getKey()}",
        )->assertNoContent();
    }

    public function test_outer_transaction_rollback_leaves_no_invitation_audit_outbox_or_job(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();

        try {
            DB::transaction(function () use ($owner, $studio): void {
                app(CreateStudioInvitation::class)->handle(
                    $studio,
                    $owner,
                    'rollback@example.com',
                    MembershipRole::Teacher,
                );

                throw new RuntimeException('roll back outer unit of work');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('roll back outer unit of work', $exception->getMessage());
        }

        $this->assertDatabaseMissing('studio_invitations', ['email_normalized' => 'rollback@example.com']);
        $this->assertDatabaseCount('studio_invitation_deliveries', 0);
        $this->assertDatabaseCount('studio_audit_events', 0);
        Queue::assertNothingPushed();

        DB::transaction(function () use ($owner, $studio): void {
            app(CreateStudioInvitation::class)->handle(
                $studio,
                $owner,
                'committed@example.com',
                MembershipRole::Teacher,
            );
        });
        Queue::assertPushed(DeliverStudioInvitation::class, 1);
    }

    public function test_pending_outbox_is_recoverable_and_terminal_digest_cleanup_is_idempotent(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup@example.com',
            MembershipRole::Teacher,
        );
        $token = $this->token($invitation);

        $accepted = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup-accepted@example.com',
            MembershipRole::Teacher,
        );
        $accepted->forceFill([
            'accepted_at' => now(),
            'accepted_by_id' => $owner->getKey(),
            'pending_key' => null,
        ])->save();
        $naturallyExpired = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup-expired@example.com',
            MembershipRole::Teacher,
        );
        $naturallyExpired->forceFill(['expires_at' => now()->subDay()])->save();
        $longLivedPending = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup-pending@example.com',
            MembershipRole::Teacher,
        );
        $longLivedPending->forceFill(['expires_at' => now()->addDays(60)])->save();
        $superseded = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup-superseded@example.com',
            MembershipRole::Teacher,
        );
        $this->travel(61)->seconds();
        $supersedingReplacement = app(ResendStudioInvitation::class)->handle($superseded, $owner);

        Queue::fake();
        Artisan::call('invitations:dispatch-pending');
        Queue::assertPushed(DeliverStudioInvitation::class, 5);

        app(RevokeStudioInvitation::class)->handle($invitation, $owner);
        $this->travel(31)->days();
        $recentlyRevoked = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'cleanup-recent@example.com',
            MembershipRole::Teacher,
        );
        app(RevokeStudioInvitation::class)->handle($recentlyRevoked, $owner);
        Artisan::call('invitations:redact-terminal-digests');

        foreach ([$invitation, $accepted, $naturallyExpired, $superseded] as $terminal) {
            $this->assertNull($terminal->refresh()->token_hash);
            $this->assertNotNull($terminal->token_redacted_at);
            $this->assertNotNull(StudioInvitationDelivery::query()
                ->where('invitation_id', $terminal->getKey())->sole()->redacted_at);
        }
        $this->assertNotNull($longLivedPending->refresh()->token_hash);
        $this->assertNotNull($supersedingReplacement->refresh()->token_hash);
        $this->assertNotNull($recentlyRevoked->refresh()->token_hash);
        $this->assertDatabaseHas('studio_audit_events', [
            'subject_id' => $invitation->getKey(),
            'event_type' => 'invitation.digest_redacted',
        ]);
        $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $token])->assertNotFound();

        Artisan::call('invitations:redact-terminal-digests');
        $this->assertSame(4, StudioAuditEvent::query()
            ->where('event_type', 'invitation.digest_redacted')
            ->count());
    }

    public function test_audit_rows_and_delivery_state_are_database_enforced(): void
    {
        Queue::fake();
        [$owner, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'immutable@example.com',
            MembershipRole::Teacher,
        );
        $event = StudioAuditEvent::query()->where('event_type', 'invitation.created')->sole();

        try {
            DB::transaction(fn () => DB::table('studio_audit_events')
                ->where('id', $event->getKey())->update(['actor_id' => null]));
            $this->fail('An immutable audit event was updated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('studio_audit_events')
                ->where('id', $event->getKey())->delete());
            $this->fail('An immutable audit event was deleted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        try {
            DB::transaction(fn () => StudioInvitationDelivery::query()
                ->where('invitation_id', $invitation->getKey())
                ->update(['status' => 'sent']));
            $this->fail('An invalid delivery state was stored.');
        } catch (QueryException $exception) {
            $this->assertTrue(
                str_contains($exception->getMessage(), 'invalid invitation delivery state')
                || str_contains($exception->getMessage(), 'studio_invitation_delivery_state_check'),
            );
        }
    }

    /** @return array{User, Studio} */
    private function owner(): array
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

    private function token(StudioInvitation $invitation): string
    {
        return app(InvitationToken::class)->derive(
            (string) $invitation->getKey(),
            $invitation->delivery_version,
        );
    }
}
