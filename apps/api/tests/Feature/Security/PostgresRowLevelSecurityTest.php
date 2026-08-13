<?php

namespace Tests\Feature\Security;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudentStatus;
use App\Jobs\DeliverStudioInvitation;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Household;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\PersonInstrument;
use App\Models\PersonTag;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Models\StudioMembership;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\StudioInvitationNotification;
use App\Support\Auth\InvitationToken;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostgresRowLevelSecurityTest extends TestCase
{
    public function test_people_extension_tables_are_default_deny_tenant_scoped_and_database_guarded(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        [$runtime, $runtimeConnectionName] = $this->runtimeConnection();
        $actor = User::factory()->create();
        $firstStudio = Studio::factory()->create();
        $secondStudio = Studio::factory()->create();
        $records = [];

        foreach ([$firstStudio, $secondStudio] as $studio) {
            $person = Person::factory()->for($studio)->create();
            $profile = StudentProfile::query()->create([
                'studio_id' => $studio->getKey(),
                'person_id' => $person->getKey(),
                'status' => StudentStatus::Lead,
                'learning_preferences' => [],
                'status_changed_at' => now(),
            ]);
            $staff = StaffProfile::query()->create([
                'studio_id' => $studio->getKey(),
                'person_id' => $person->getKey(),
                'roles' => ['teacher'],
                'status' => 'active',
            ]);
            $instrument = Instrument::query()->create([
                'studio_id' => $studio->getKey(),
                'name' => 'Piano '.$studio->getKey(),
                'active' => true,
            ]);
            $personInstrument = PersonInstrument::query()->create([
                'studio_id' => $studio->getKey(),
                'person_id' => $person->getKey(),
                'instrument_id' => $instrument->getKey(),
                'relationship' => 'studies',
                'is_primary' => true,
            ]);
            $tag = Tag::query()->create([
                'studio_id' => $studio->getKey(),
                'name' => 'Priority '.$studio->getKey(),
                'active' => true,
            ]);
            $personTag = PersonTag::query()->create([
                'studio_id' => $studio->getKey(),
                'person_id' => $person->getKey(),
                'tag_id' => $tag->getKey(),
            ]);
            $definition = CustomFieldDefinition::query()->create([
                'studio_id' => $studio->getKey(),
                'key' => 'level-'.$studio->getKey(),
                'name' => 'Level',
                'type' => 'text',
                'applies_to' => 'person',
                'active' => true,
            ]);
            $value = CustomFieldValue::query()->create([
                'studio_id' => $studio->getKey(),
                'definition_id' => $definition->getKey(),
                'person_id' => $person->getKey(),
                'value' => ['value' => 'A'],
            ]);
            $transition = StudentStatusTransition::query()->create([
                'studio_id' => $studio->getKey(),
                'student_profile_id' => $profile->getKey(),
                'person_id' => $person->getKey(),
                'actor_id' => $actor->getKey(),
                'previous_status' => null,
                'new_status' => StudentStatus::Lead,
                'reason' => 'Profile created.',
                'occurred_at' => now(),
            ]);
            $records[(string) $studio->getKey()] = compact(
                'person',
                'profile',
                'staff',
                'instrument',
                'personInstrument',
                'tag',
                'personTag',
                'definition',
                'value',
                'transition',
            );
        }

        $tenantTables = [
            'staff_profiles',
            'instruments',
            'person_instruments',
            'tags',
            'person_tags',
            'custom_field_definitions',
            'custom_field_values',
            'student_status_transitions',
        ];

        try {
            foreach ($tenantTables as $table) {
                $this->assertSame(0, $runtime->table($table)->count(), "{$table} must default deny.");
            }

            $this->setContext($runtime, 'app.current_studio_id', (string) $firstStudio->getKey());

            foreach ($tenantTables as $table) {
                $this->assertSame(1, $runtime->table($table)->count(), "{$table} leaked another tenant.");
            }

            $first = $records[(string) $firstStudio->getKey()];
            $second = $records[(string) $secondStudio->getKey()];
            $crossTenantAssignmentDenied = false;

            try {
                $runtime->table('person_tags')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $firstStudio->getKey(),
                    'person_id' => $first['person']->getKey(),
                    'tag_id' => $second['tag']->getKey(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossTenantAssignmentDenied = true;
            }

            $this->assertTrue($crossTenantAssignmentDenied);
            $directStatusUpdateDenied = false;

            try {
                $runtime->table('student_profiles')
                    ->where('id', $first['profile']->getKey())
                    ->update(['status' => StudentStatus::Active->value]);
            } catch (QueryException) {
                $directStatusUpdateDenied = true;
            }

            $this->assertTrue($directStatusUpdateDenied);
            $runtime->table('student_status_transitions')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $firstStudio->getKey(),
                'student_profile_id' => $first['profile']->getKey(),
                'person_id' => $first['person']->getKey(),
                'actor_id' => $actor->getKey(),
                'previous_status' => StudentStatus::Lead->value,
                'new_status' => StudentStatus::Trial->value,
                'reason' => 'RLS lifecycle proof.',
                'occurred_at' => now()->addSecond(),
            ]);
            $this->assertSame(
                StudentStatus::Trial->value,
                $runtime->table('student_profiles')->where('id', $first['profile']->getKey())->value('status'),
            );
            $this->assertSame(
                2,
                $runtime->table('people')->where('id', $first['person']->getKey())->value('version'),
            );
            $historyMutationDenied = false;

            try {
                $runtime->table('student_status_transitions')
                    ->where('id', $first['transition']->getKey())
                    ->update(['reason' => 'rewritten']);
            } catch (QueryException) {
                $historyMutationDenied = true;
            }

            $this->assertTrue($historyMutationDenied);
        } finally {
            DB::purge($runtimeConnectionName);
        }
    }

    public function test_restricted_runtime_role_is_default_deny_and_cannot_cross_studios(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        $runtimeUsername = (string) env('DB_RUNTIME_USERNAME');
        $runtimePassword = (string) env('DB_RUNTIME_PASSWORD');

        if ($runtimeUsername === '' || $runtimePassword === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }

        $firstStudio = Studio::factory()->create();
        $secondStudio = Studio::factory()->create();
        $firstHousehold = Household::factory()->for($firstStudio)->create();
        Household::factory()->for($secondStudio)->create();
        $firstPerson = Person::factory()->for($firstStudio)->create();
        $secondPerson = Person::factory()->for($secondStudio)->create();
        $firstStudent = StudentProfile::query()->create([
            'studio_id' => $firstStudio->getKey(),
            'person_id' => $firstPerson->getKey(),
            'status' => StudentStatus::Lead,
            'learning_preferences' => [],
        ]);
        StudentProfile::query()->create([
            'studio_id' => $secondStudio->getKey(),
            'person_id' => $secondPerson->getKey(),
            'status' => StudentStatus::Active,
            'learning_preferences' => [],
        ]);
        $runtimeConnectionName = 'pgsql_runtime_test';
        $baseConnection = config('database.connections.pgsql');

        config([
            "database.connections.{$runtimeConnectionName}" => array_replace(
                $baseConnection,
                [
                    'username' => $runtimeUsername,
                    'password' => $runtimePassword,
                ],
            ),
        ]);
        DB::purge($runtimeConnectionName);
        $runtime = DB::connection($runtimeConnectionName);

        try {
            $this->assertRestrictedRole($runtime, $runtimeUsername);
            $this->assertSame(0, $runtime->table('households')->count());
            $this->assertSame(0, $runtime->table('people')->count());
            $this->assertSame(0, $runtime->table('student_profiles')->count());

            $runtime->statement(
                "select set_config('app.current_studio_id', ?, false)",
                [$firstStudio->getKey()],
            );

            $this->assertSame(
                [$firstHousehold->getKey()],
                $runtime->table('households')->pluck('id')->all(),
            );
            $this->assertSame([$firstPerson->getKey()], $runtime->table('people')->pluck('id')->all());
            $this->assertSame([$firstStudent->getKey()], $runtime->table('student_profiles')->pluck('id')->all());

            $allowedId = (string) Str::ulid();
            $runtime->table('households')->insert([
                'id' => $allowedId,
                'studio_id' => $firstStudio->getKey(),
                'name' => 'RLS allowed household',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertSame(2, $runtime->table('households')->count());

            $allowedPersonId = (string) Str::ulid();
            $runtime->table('people')->insert([
                'id' => $allowedPersonId,
                'studio_id' => $firstStudio->getKey(),
                'first_name' => 'Runtime',
                'last_name' => 'Student',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $runtime->table('student_profiles')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $firstStudio->getKey(),
                'person_id' => $allowedPersonId,
                'status' => StudentStatus::Lead->value,
                'learning_preferences' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertSame(2, $runtime->table('people')->count());
            $this->assertSame(2, $runtime->table('student_profiles')->count());

            $crossStudioWriteWasDenied = false;

            try {
                $runtime->table('households')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $secondStudio->getKey(),
                    'name' => 'RLS rejected household',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossStudioWriteWasDenied = true;
            }

            $this->assertTrue($crossStudioWriteWasDenied);

            $crossStudioPersonWasDenied = false;

            try {
                $runtime->table('people')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $secondStudio->getKey(),
                    'first_name' => 'Rejected',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossStudioPersonWasDenied = true;
            }

            $this->assertTrue($crossStudioPersonWasDenied);
        } finally {
            DB::purge($runtimeConnectionName);
            $firstStudio->forceDelete();
            $secondStudio->forceDelete();
        }
    }

    public function test_membership_and_invitation_policies_are_default_deny_and_scope_direct_reads_and_writes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        [$runtime, $runtimeConnectionName] = $this->runtimeConnection();
        $firstUser = User::factory()->create(['email' => 'security-direct-first@example.com']);
        $secondUser = User::factory()->create(['email' => 'security-direct-second@example.com']);
        $invitee = User::factory()->create(['email' => 'security-direct-invitee@example.com']);
        $firstStudio = Studio::factory()->create(['slug' => 'security-direct-first']);
        $secondStudio = Studio::factory()->create(['slug' => 'security-direct-second']);
        $firstMembership = $this->membership($firstUser, $firstStudio, MembershipRole::Owner);
        $this->membership($secondUser, $secondStudio, MembershipRole::Owner);
        $firstToken = Str::random(64);
        $secondToken = Str::random(64);
        $firstInvitation = $this->invitation($firstStudio, $firstUser, 'first-invitee@example.com', $firstToken);
        $secondInvitation = $this->invitation($secondStudio, $secondUser, $invitee->email, $secondToken);

        try {
            $duplicateOwnerWasDenied = false;

            try {
                $this->membership($secondUser, $firstStudio, MembershipRole::Owner);
            } catch (QueryException) {
                $duplicateOwnerWasDenied = true;
            }

            $this->assertTrue($duplicateOwnerWasDenied);
            $this->assertSame(0, $runtime->table('studio_memberships')->count());
            $this->assertSame(0, $runtime->table('studio_invitations')->count());

            $this->setContext($runtime, 'app.current_user_id', (string) $firstUser->getKey());
            $this->assertSame(
                [$firstMembership->getKey()],
                $runtime->table('studio_memberships')->pluck('id')->all(),
            );
            $this->assertSame(0, $runtime->table('studio_invitations')->count());
            $this->assertSame(1, $runtime->table('studio_memberships')
                ->where('id', $firstMembership->getKey())
                ->update(['preferences' => json_encode(['density' => 'compact'], JSON_THROW_ON_ERROR)]));

            $protectedUpdateWasDenied = false;

            try {
                $runtime->table('studio_memberships')
                    ->where('id', $firstMembership->getKey())
                    ->update(['role' => MembershipRole::Administrator->value]);
            } catch (QueryException) {
                $protectedUpdateWasDenied = true;
            }

            $this->assertTrue($protectedUpdateWasDenied);

            $this->setContext($runtime, 'app.current_user_id', '');
            $this->setContext($runtime, 'app.current_studio_id', $firstStudio->getKey());
            $this->assertSame(1, $runtime->table('studio_memberships')->count());
            $this->assertSame([$firstInvitation->getKey()], $runtime->table('studio_invitations')->pluck('id')->all());

            $crossStudioWriteWasDenied = false;

            try {
                $runtime->table('studio_memberships')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $secondStudio->getKey(),
                    'user_id' => $firstUser->getKey(),
                    'role' => MembershipRole::Teacher->value,
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $crossStudioWriteWasDenied = true;
            }

            $this->assertTrue($crossStudioWriteWasDenied);

            $this->setContext($runtime, 'app.current_studio_id', '');
            $this->setContext($runtime, 'app.current_invitation_token_hash', hash('sha256', $secondToken));
            $this->assertSame([$secondInvitation->getKey()], $runtime->table('studio_invitations')->pluck('id')->all());
            $this->assertSame(0, $runtime->table('studio_invitations')
                ->where('id', $secondInvitation->getKey())
                ->update(['expires_at' => now()->addYear()]));

            $this->setContext($runtime, 'app.current_user_id', (string) $invitee->getKey());
            $wrongInvitationWriteWasDenied = false;

            try {
                $runtime->table('studio_memberships')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $firstStudio->getKey(),
                    'user_id' => $invitee->getKey(),
                    'role' => MembershipRole::Teacher->value,
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $wrongInvitationWriteWasDenied = true;
            }

            $this->assertTrue($wrongInvitationWriteWasDenied);

            $acceptedMembershipId = (string) Str::ulid();
            $runtime->table('studio_memberships')->insert([
                'id' => $acceptedMembershipId,
                'studio_id' => $secondStudio->getKey(),
                'user_id' => $invitee->getKey(),
                'role' => MembershipRole::Teacher->value,
                'status' => MembershipStatus::Active->value,
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertSame(1, $runtime->table('studio_invitations')
                ->where('id', $secondInvitation->getKey())
                ->update([
                    'accepted_by_id' => $invitee->getKey(),
                    'accepted_at' => now(),
                    'pending_key' => null,
                    'updated_at' => now(),
                ]));
            $this->assertSame([$acceptedMembershipId], $runtime->table('studio_memberships')->pluck('id')->all());
        } finally {
            DB::purge($runtimeConnectionName);
            $firstStudio->forceDelete();
            $secondStudio->forceDelete();
            $firstUser->delete();
            $secondUser->delete();
            $invitee->delete();
        }
    }

    public function test_runtime_http_invitation_onboarding_studio_index_queue_and_filament_paths_work(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        Notification::fake();
        Queue::fake();
        [$runtime, $runtimeConnectionName] = $this->runtimeConnection();
        $originalDefault = DB::getDefaultConnection();
        $owner = User::factory()->create(['email' => 'security-runtime-owner@example.com']);
        $invitee = User::factory()->unverified()->create(['email' => 'security-runtime-invitee@example.com']);
        $onboardingUser = User::factory()->create(['email' => 'security-runtime-onboarding@example.com']);
        $studio = Studio::factory()->create(['name' => 'Runtime Studio', 'slug' => 'security-runtime-studio']);
        $this->membership($owner, $studio, MembershipRole::Owner);
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            $invitee->email,
            MembershipRole::Teacher,
        );
        $token = app(InvitationToken::class)->derive(
            $invitation->getKey(),
            $invitation->delivery_version,
        );
        $registrationInvitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $owner,
            'security-runtime-registration@example.com',
            MembershipRole::Teacher,
        );
        $registrationToken = app(InvitationToken::class)->derive(
            $registrationInvitation->getKey(),
            $registrationInvitation->delivery_version,
        );
        $queuedNotification = new StudioInvitationNotification(
            $invitation->getKey(),
            $invitation->delivery_version,
        );
        $queuedJob = null;
        Queue::assertPushed(
            DeliverStudioInvitation::class,
            function (DeliverStudioInvitation $job) use ($invitation, &$queuedJob): bool {
                if ($job->invitationId !== $invitation->getKey()) {
                    return false;
                }

                $queuedJob = $job;

                return true;
            },
        );

        config(['database.default' => $runtimeConnectionName]);
        DB::setDefaultConnection($runtimeConnectionName);

        try {
            $this->assertInstanceOf(DeliverStudioInvitation::class, $queuedJob);
            app()->call([$queuedJob, 'handle']);
            Notification::assertSentOnDemand(StudioInvitationNotification::class);

            $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $token])
                ->assertOk()
                ->assertJsonPath('data.status', 'pending');
            $this->postJson('/api/v1/invitations/preview', ['invitation_token' => $registrationToken])
                ->assertOk()
                ->assertJsonPath('data.status', 'pending');

            $this->postJson('/api/v1/auth/register', [
                'name' => 'Runtime Registration',
                'email' => 'SECURITY-RUNTIME-REGISTRATION@EXAMPLE.COM',
                'password' => 'Correct-Horse-42!',
                'password_confirmation' => 'Correct-Horse-42!',
                'invitation_token' => $registrationToken,
            ])->assertAccepted()->assertExactJson([
                'message' => 'If registration can be completed, check your email for next steps.',
            ]);
            $registeredUserId = DB::connection($originalDefault)
                ->table('users')
                ->where('email', 'security-runtime-registration@example.com')
                ->value('id');
            $this->assertNotNull($registeredUserId);
            $this->assertFalse(DB::connection($originalDefault)
                ->table('studio_memberships')
                ->where('user_id', $registeredUserId)
                ->exists());
            $this->assertNull(DB::connection($originalDefault)
                ->table('studio_invitations')
                ->where('id', $registrationInvitation->getKey())
                ->value('accepted_at'));
            Auth::forgetGuards();
            session()->invalidate();
            $this->defaultCookies = [];
            $login = $this->postJson('/api/v1/auth/login', [
                'email' => $owner->email,
                'password' => 'password',
            ])->assertOk()->assertJsonPath('two_factor', false);
            $sessionCookie = $login->getCookie((string) config('session.cookie'));
            $this->assertNotNull($sessionCookie);
            $this->withCredentials()->withCookie((string) config('session.cookie'), $sessionCookie->getValue());
            Auth::forgetGuards();

            $this->withHeader('Origin', 'http://localhost:3000')
                ->postJson('/api/v1/auth/user/confirm-password', [
                    'password' => 'password',
                ])->assertCreated();
            $this->getJson('/api/v1/studios')
                ->assertOk()
                ->assertJsonPath('data.0.slug', $studio->slug);
            $this->getJson("/api/v1/studios/{$studio->slug}/invitations")
                ->assertOk()
                ->assertJsonFragment(['id' => $invitation->getKey()]);
            $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'security-runtime-managed@example.com',
                'role' => MembershipRole::Teacher->value,
            ])->assertCreated();

            Auth::forgetGuards();
            $filamentResponse = $this->get("/manage/studio/{$studio->slug}");
            $this->assertSame(
                200,
                $filamentResponse->getStatusCode(),
                'Filament runtime path failed.',
            );

            Auth::guard('web')->logout();
            session()->invalidate();
            Auth::forgetGuards();
            $this->defaultCookies = [];
            Sanctum::actingAs($invitee);
            $acceptanceResponse = $this->postJson('/api/v1/invitations/accept', ['invitation_token' => $token]);
            $this->assertSame(200, $acceptanceResponse->getStatusCode(), 'Invitation acceptance runtime path failed.');
            $acceptanceResponse->assertJsonPath('data.slug', $studio->slug);
            $this->assertFalse($queuedNotification->shouldSend((object) [], 'mail'));

            Auth::forgetGuards();
            Sanctum::actingAs($onboardingUser);
            $onboardingResponse = $this->postJson('/api/v1/onboarding', [
                'studio' => [
                    'name' => 'Runtime Onboarding Studio',
                    'slug' => 'security-runtime-onboarding-studio',
                ],
                'workspace_mode' => 'owner',
                'primary_goal' => 'growth',
            ]);
            $this->assertSame(200, $onboardingResponse->getStatusCode(), 'Onboarding runtime path failed.');
            $onboardingResponse->assertJsonPath('data.membership.role', MembershipRole::Owner->value);
            $this->getJson('/api/v1/studios')->assertOk()->assertJsonCount(1, 'data');
        } finally {
            Auth::forgetGuards();
            DB::purge($runtimeConnectionName);
            config(['database.default' => $originalDefault]);
            DB::setDefaultConnection($originalDefault);
            User::query()->where('email', 'like', 'security-runtime-%')->delete();
        }
    }

    public function test_invitation_delivery_and_audit_rls_are_default_deny_cross_tenant_safe_and_bearer_scoped(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        Queue::fake();
        [$runtime, $runtimeConnectionName] = $this->runtimeConnection();
        $owner = User::factory()->create(['email' => 'security-lifecycle-owner@example.com']);
        $invitee = User::factory()->create(['email' => 'security-lifecycle-invitee@example.com']);
        $firstStudio = Studio::factory()->create(['slug' => 'security-lifecycle-first']);
        $secondStudio = Studio::factory()->create(['slug' => 'security-lifecycle-second']);
        $this->membership($owner, $firstStudio, MembershipRole::Owner);
        $secondOwner = User::factory()->create(['email' => 'security-lifecycle-second-owner@example.com']);
        $this->membership($secondOwner, $secondStudio, MembershipRole::Owner);
        $firstInvitation = app(CreateStudioInvitation::class)->handle(
            $firstStudio,
            $owner,
            $invitee->email,
            MembershipRole::Teacher,
        );
        $secondInvitation = app(CreateStudioInvitation::class)->handle(
            $secondStudio,
            $secondOwner,
            'security-lifecycle-second-invitee@example.com',
            MembershipRole::Teacher,
        );
        $token = app(InvitationToken::class)->derive(
            $firstInvitation->getKey(),
            $firstInvitation->delivery_version,
        );
        $terminalInvitations = [];

        foreach (['revoked', 'expired', 'superseded'] as $terminalState) {
            $terminalUser = User::factory()->create([
                'email' => "security-lifecycle-{$terminalState}@example.com",
            ]);
            $terminalInvitation = app(CreateStudioInvitation::class)->handle(
                $firstStudio,
                $owner,
                $terminalUser->email,
                MembershipRole::Teacher,
            );
            $this->membership($terminalUser, $firstStudio, MembershipRole::Teacher);
            $terminalInvitation->forceFill(match ($terminalState) {
                'revoked' => ['revoked_at' => now(), 'pending_key' => null],
                'expired' => ['expires_at' => now()->subSecond()],
                'superseded' => ['superseded_at' => now(), 'pending_key' => null],
            })->save();
            $terminalInvitations[] = [$terminalUser, $terminalInvitation, app(InvitationToken::class)->derive(
                $terminalInvitation->getKey(),
                $terminalInvitation->delivery_version,
            )];
        }

        try {
            $this->assertSame(0, $runtime->table('studio_invitation_deliveries')->count());
            $this->assertSame(0, $runtime->table('studio_audit_events')->count());
            $this->assertSame(
                $firstStudio->getKey(),
                $runtime->scalar(
                    'select public.app_resolve_invitation_delivery_studio(?, ?)',
                    [$firstInvitation->getKey(), $firstInvitation->delivery_version],
                ),
            );
            $this->assertNull($runtime->scalar(
                'select public.app_resolve_invitation_delivery_studio(?, ?)',
                [$firstInvitation->getKey(), 999],
            ));

            $this->setContext($runtime, 'app.current_studio_id', $firstStudio->getKey());
            $this->assertSame(4, $runtime->table('studio_invitation_deliveries')->count());
            $this->assertSame(8, $runtime->table('studio_audit_events')->count());
            $this->assertSame(0, $runtime->table('studio_invitation_deliveries')
                ->where('invitation_id', $secondInvitation->getKey())
                ->update(['status' => 'suppressed']));

            $this->setContext($runtime, 'app.current_studio_id', '');
            $this->setContext($runtime, 'app.current_user_id', (string) $invitee->getKey());
            $this->setContext($runtime, 'app.current_invitation_token_hash', hash('sha256', $token));
            $this->assertSame([$firstInvitation->getKey()], $runtime->table('studio_invitations')->pluck('id')->all());

            foreach ([
                ['role' => MembershipRole::Administrator->value],
                ['token_hash' => str_repeat('a', 64)],
                ['lineage_id' => $secondInvitation->getKey()],
                ['superseded_at' => now()],
            ] as $mutation) {
                $denied = false;

                try {
                    $denied = $runtime->table('studio_invitations')
                        ->where('id', $firstInvitation->getKey())
                        ->update($mutation) === 0;
                } catch (QueryException) {
                    $denied = true;
                }

                $this->assertTrue($denied);
            }

            $membershipDenied = false;

            try {
                $runtime->table('studio_memberships')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $firstStudio->getKey(),
                    'user_id' => $invitee->getKey(),
                    'role' => MembershipRole::Administrator->value,
                    'status' => MembershipStatus::Active->value,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException) {
                $membershipDenied = true;
            }

            $this->assertTrue($membershipDenied);
            $this->assertSame(0, $runtime->table('studio_invitation_deliveries')
                ->where('invitation_id', $firstInvitation->getKey())
                ->update(['status' => 'suppressed']));
            $auditDenied = false;

            try {
                $runtime->table('studio_audit_events')->insert([
                    'id' => (string) Str::ulid(),
                    'studio_id' => $firstStudio->getKey(),
                    'event_type' => 'invitation.accepted',
                    'subject_type' => 'studio_invitation',
                    'subject_id' => $firstInvitation->getKey(),
                    'actor_id' => $invitee->getKey(),
                    'metadata' => '{}',
                    'occurred_at' => now(),
                ]);
            } catch (QueryException) {
                $auditDenied = true;
            }

            $this->assertTrue($auditDenied);
            $invalidAuditContextDenied = false;

            try {
                $runtime->statement(
                    'select public.app_finalize_invitation_acceptance(?, ?, ?, ?, ?)',
                    [
                        $firstInvitation->getKey(),
                        (string) Str::ulid(),
                        (string) Str::ulid(),
                        str_repeat('A', 43),
                        str_repeat('z', 64),
                    ],
                );
            } catch (QueryException) {
                $invalidAuditContextDenied = true;
            }

            $this->assertTrue($invalidAuditContextDenied);

            foreach ($terminalInvitations as [$terminalUser, $terminalInvitation, $terminalToken]) {
                $this->setContext($runtime, 'app.current_user_id', (string) $terminalUser->getKey());
                $this->setContext(
                    $runtime,
                    'app.current_invitation_token_hash',
                    hash('sha256', $terminalToken),
                );
                $terminalAcceptanceDenied = false;

                try {
                    $terminalAcceptanceDenied = $runtime->table('studio_invitations')
                        ->where('id', $terminalInvitation->getKey())
                        ->update([
                            'accepted_by_id' => $terminalUser->getKey(),
                            'accepted_at' => now(),
                            'pending_key' => null,
                        ]) === 0;
                } catch (QueryException) {
                    $terminalAcceptanceDenied = true;
                }

                $this->assertTrue($terminalAcceptanceDenied);
            }
        } finally {
            DB::purge($runtimeConnectionName);
        }
    }

    public function test_user_session_registry_is_default_deny_and_user_scoped_for_the_restricted_runtime(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the RLS integration test.');
        }

        [$runtime, $runtimeConnectionName] = $this->runtimeConnection();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstId = (string) Str::ulid();
        $secondId = (string) Str::ulid();
        $attributes = [
            'ip_address' => '203.0.113.10',
            'user_agent' => 'RLS test',
            'created_at' => now(),
            'last_seen_at' => now(),
        ];
        DB::table('user_sessions')->insert([
            [...$attributes, 'id' => $firstId, 'session_id' => 'rls-first', 'user_id' => $firstUser->getKey()],
            [...$attributes, 'id' => $secondId, 'session_id' => 'rls-second', 'user_id' => $secondUser->getKey()],
        ]);

        try {
            $this->assertSame(0, $runtime->table('user_sessions')->count());
            $this->setContext($runtime, 'app.current_user_id', (string) $firstUser->getKey());
            $this->assertSame([$firstId], $runtime->table('user_sessions')->pluck('id')->all());

            $crossUserInsertDenied = false;

            try {
                $runtime->table('user_sessions')->insert([
                    ...$attributes,
                    'id' => (string) Str::ulid(),
                    'session_id' => 'rls-cross-user',
                    'user_id' => $secondUser->getKey(),
                ]);
            } catch (QueryException) {
                $crossUserInsertDenied = true;
            }

            $this->assertTrue($crossUserInsertDenied);
            $crossUserUpdateDenied = false;

            try {
                $runtime->table('user_sessions')
                    ->where('id', $firstId)
                    ->update(['user_id' => $secondUser->getKey()]);
            } catch (QueryException) {
                $crossUserUpdateDenied = true;
            }

            $this->assertTrue($crossUserUpdateDenied);
            $this->assertSame(0, $runtime->table('user_sessions')
                ->where('id', $secondId)
                ->delete());
            $this->setContext($runtime, 'app.current_user_id', (string) $secondUser->getKey());
            $this->assertSame([$secondId], $runtime->table('user_sessions')->pluck('id')->all());
        } finally {
            DB::purge($runtimeConnectionName);
            UserSession::query()->whereIn('id', [$firstId, $secondId])->delete();
            $firstUser->delete();
            $secondUser->delete();
        }
    }

    private function assertRestrictedRole(ConnectionInterface $connection, string $role): void
    {
        $attributes = $connection->table('pg_roles')
            ->where('rolname', $role)
            ->first(['rolsuper', 'rolbypassrls']);

        $this->assertNotNull($attributes);
        $this->assertFalse($attributes->rolsuper);
        $this->assertFalse($attributes->rolbypassrls);
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $runtimeUsername = (string) env('DB_RUNTIME_USERNAME');
        $runtimePassword = (string) env('DB_RUNTIME_PASSWORD');

        if ($runtimeUsername === '' || $runtimePassword === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }

        $name = 'pgsql_runtime_'.Str::lower((string) Str::ulid());
        config([
            "database.connections.{$name}" => array_replace(
                config('database.connections.pgsql'),
                ['username' => $runtimeUsername, 'password' => $runtimePassword],
            ),
        ]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }

    private function setContext(ConnectionInterface $connection, string $key, string $value): void
    {
        $connection->statement('select set_config(?, ?, false)', [$key, $value]);
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

    private function invitation(Studio $studio, User $inviter, string $email, string $token): StudioInvitation
    {
        $id = (string) Str::ulid();
        $invitation = StudioInvitation::query()->create([
            'id' => $id,
            'studio_id' => $studio->getKey(),
            'lineage_id' => $id,
            'delivery_version' => 1,
            'email_normalized' => User::normalizeEmail($email),
            'role' => MembershipRole::Teacher,
            'token_hash' => hash('sha256', $token),
            'pending_key' => $studio->getKey().'|'.User::normalizeEmail($email),
            'invited_by_id' => $inviter->getKey(),
            'expires_at' => now()->addDay(),
        ]);

        StudioInvitationDelivery::query()->create([
            'studio_id' => $studio->getKey(),
            'invitation_id' => $invitation->getKey(),
            'delivery_version' => 1,
            'status' => 'pending',
        ]);

        return $invitation;
    }
}
