<?php

namespace Tests\Feature\Auth;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Pages\Auth\Login as FilamentLogin;
use App\Http\Middleware\EnforceBrowserSessionLifetime;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Models\UserSession;
use App\Providers\AppServiceProvider;
use App\Support\Auth\LoginRateLimitKey;
use App\Support\Auth\SensitiveRateLimitKey;
use App\Support\Tenancy\RequestDatabaseContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use LogicException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class IdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42!';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.timebox_duration' => 1,
            'session.driver' => 'array',
        ]);
        app('session')->forgetDrivers();
        $this->withHeader('Origin', 'http://localhost:3000')->withCredentials();
    }

    public function test_totp_setup_confirmation_recovery_and_disable_require_recent_password_and_are_not_cacheable(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $this->postJson('/api/v1/auth/user/two-factor-authentication')
            ->assertStatus(423)
            ->assertJsonPath('message', 'Password confirmation required.');
        $this->postJson('/api/v1/auth/user/confirm-password', [
            'password' => self::PASSWORD,
        ])->assertCreated();
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertNotFound();
        $this->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertNotFound();
        $this->postJson('/api/v1/auth/user/two-factor-authentication')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());

        $secretResponse = $this->getJson('/api/v1/auth/user/two-factor-secret-key')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['secretKey']);
        $this->getJson('/api/v1/auth/user/two-factor-qr-code')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['svg', 'url']);
        $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertNotFound();

        $code = app(Google2FA::class)->getCurrentOtp($secretResponse->json('secretKey'));
        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', [
            'code' => $code,
        ])->assertOk();
        $this->assertTrue($user->refresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNull($user->two_factor_setup_started_at);
        $this->getJson('/api/v1/auth/user/two-factor-secret-key')->assertNotFound();
        $this->getJson('/api/v1/auth/user/two-factor-qr-code')->assertNotFound();
        $recoveryCodes = $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json();
        $this->assertCount(8, $recoveryCodes);

        $this->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonPath('data.passkeys_count', 0);

        $this->postJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk();
        $regenerated = $this->getJson('/api/v1/auth/user/two-factor-recovery-codes')->assertOk()->json();
        $this->assertCount(8, $regenerated);
        $this->assertNotSame($recoveryCodes, $regenerated);

        $this->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
        $this->assertFalse($user->refresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_totp_login_challenge_has_a_five_minute_lifetime_rate_limit_and_same_code_replay_defense(): void
    {
        [$user, $secret] = $this->twoFactorUser();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);
        $this->assertGuest();
        $this->assertIsInt(session('login.issued_at'));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
        $this->assertNull(session('login.id'));
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])
            ->assertTooManyRequests();

        RateLimiter::clear(md5('two-factor'.app(SensitiveRateLimitKey::class)->for(
            'two-factor-challenge',
            (string) $user->getKey(),
            '127.0.0.1',
        )));
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])
            ->assertNoContent();
        $this->assertAuthenticatedAs($user);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        session()->put('login.issued_at', now()->subSeconds(301)->timestamp);
        $freshCode = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $freshCode])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull(session('login.id'));
    }

    public function test_pending_totp_setup_expires_after_ten_minutes_and_cannot_be_confirmed(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        $this->postJson('/api/v1/auth/user/confirm-password', [
            'password' => self::PASSWORD,
        ])->assertCreated();
        $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        $user->refresh()->forceFill([
            'two_factor_setup_started_at' => now()->subSeconds(601),
        ])->save();
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', [
            'code' => $code,
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_setup_started_at);
    }

    public function test_totp_recovery_code_is_single_use(): void
    {
        [$user] = $this->twoFactorUser(['single-use-recovery']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'recovery_code' => 'single-use-recovery',
        ])->assertNoContent();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'recovery_code' => 'single-use-recovery',
        ])->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
    }

    public function test_passkey_options_inventory_label_validation_and_ownership_are_hardened(): void
    {
        config([
            'fortify.passkeys.relying_party_id' => 'localhost',
            'fortify.passkeys.allowed_origins' => ['http://localhost:3000'],
        ]);
        $user = $this->user();
        $this->actingAs($user, 'web');
        $this->postJson('/api/v1/auth/user/confirm-password', [
            'password' => self::PASSWORD,
        ])->assertCreated();

        $this->getJson('/api/v1/auth/user/passkeys/options')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('options.rp.id', 'localhost')
            ->assertJsonPath('options.user.name', $user->email);

        $this->postJson('/api/v1/auth/user/passkeys', [
            'name' => str_repeat('a', 81),
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/auth/user/passkeys', [
            'name' => str_repeat('a', 80),
        ])->assertUnprocessable()
            ->assertJsonMissingValidationErrors('name')
            ->assertJsonValidationErrors('credential');

        $passkey = $user->passkeys()->create([
            'name' => '<script>alert(1)</script>',
            'credential_id' => Str::random(48),
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);
        $inventory = $this->getJson('/api/v1/auth/passkeys')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', (string) $passkey->getKey())
            ->assertJsonPath('data.0.name', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $inventory->getContent());
        $this->getJson('/api/v1/auth/user')
            ->assertJsonPath('data.passkeys_count', 1);

        $otherPasskey = $this->user()->passkeys()->create([
            'name' => 'Other user key',
            'credential_id' => Str::random(48),
            'credential' => [],
        ]);
        $this->deleteJson('/api/v1/auth/user/passkeys/'.$otherPasskey->getKey())->assertForbidden();
        $this->deleteJson('/api/v1/auth/user/passkeys/'.$passkey->getKey())
            ->assertOk()->assertJsonPath('status', 'passkey-deleted');
    }

    public function test_recent_password_confirmation_rejects_stale_future_malformed_and_missing_values_on_invitation_mutations(): void
    {
        [$user, $studio] = $this->owner();
        $invitation = app(CreateStudioInvitation::class)->handle(
            $studio,
            $user,
            'revoke@example.com',
            MembershipRole::Teacher,
        );
        $passkey = $user->passkeys()->create([
            'name' => 'Step-up protected key',
            'credential_id' => Str::random(48),
            'credential' => [],
        ]);
        $this->actingAs($user, 'web');

        foreach ([now()->subSeconds(601)->timestamp, now()->addSecond()->timestamp, 'not-a-timestamp', null] as $value) {
            $this->withSession(['auth.password_confirmed_at' => $value]);
            $this->postJson('/api/v1/auth/user/two-factor-authentication')->assertStatus(423);
            $this->getJson('/api/v1/auth/user/passkeys/options')->assertStatus(423);
            $this->deleteJson('/api/v1/auth/user/passkeys/'.$passkey->getKey())->assertStatus(423);
            $this->postJson("/api/v1/studios/{$studio->slug}/invitations", [
                'email' => 'blocked@example.com',
                'role' => MembershipRole::Teacher->value,
            ])->assertStatus(423)->assertJsonPath('message', 'Password confirmation required.');
            $this->deleteJson("/api/v1/studios/{$studio->slug}/invitations/{$invitation->getKey()}")
                ->assertStatus(423);
        }

        $this->assertDatabaseMissing('studio_invitations', ['email_normalized' => 'blocked@example.com']);
        $this->assertNull($invitation->refresh()->revoked_at);
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->getKey()]);
    }

    public function test_database_session_inventory_is_safe_and_can_revoke_other_or_current_sessions(): void
    {
        $this->enableDatabaseSessions();
        $user = $this->user();
        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
        );
        $this->login($user);
        $this->assertDatabaseHas('sessions', ['user_id' => $user->getKey()]);
        $this->asDatabaseUser($user, fn () => $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->getKey(),
        ]));
        $registrySessionId = $this->asDatabaseUser(
            $user,
            fn () => UserSession::query()->where('user_id', $user->getKey())->sole()->session_id,
        );
        $this->assertSame(
            DB::table('sessions')->where('user_id', $user->getKey())->sole()->id,
            $registrySessionId,
        );
        $this->assertSame(session()->getId(), $registrySessionId);
        $other = $this->trackedSession($user, 'other-browser-session');

        $inventory = $this->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertJsonMissingPath('data.0.user_agent');
        $current = collect($inventory->json('data'))->firstWhere('current', true);
        $this->assertIsArray($current);
        $this->assertSame('Chrome on macOS', $current['device']);
        $this->assertSame('127.0.0.0/24 (approximate)', $current['approximate_location']);
        $this->assertStringNotContainsString('Mozilla/5.0', $inventory->getContent());
        $this->assertStringNotContainsString('127.0.0.1', $inventory->getContent());

        $this->postJson('/api/v1/auth/user/confirm-password', [
            'password' => self::PASSWORD,
        ])->assertCreated();
        $this->deleteJson('/api/v1/auth/sessions/'.$other->getKey())->assertNoContent();
        $this->asDatabaseUser($user, fn () => $this->assertDatabaseMissing('user_sessions', [
            'id' => $other->getKey(),
        ]));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-browser-session']);

        $this->trackedSession($user, 'another-browser-session');
        $this->deleteJson('/api/v1/auth/sessions/others')->assertNoContent();
        $this->getJson('/api/v1/auth/sessions')->assertOk()->assertJsonCount(1, 'data');

        $currentPublicId = $this->getJson('/api/v1/auth/sessions')->json('data.0.id');
        $currentRawId = session()->getId();
        $this->deleteJson('/api/v1/auth/sessions/'.$currentPublicId)->assertNoContent();
        Auth::forgetGuards();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('sessions', ['id' => $currentRawId]);
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_idle_absolute_missing_metadata_and_error_activity_session_boundaries_are_enforced(): void
    {
        $this->enableDatabaseSessions();
        $user = $this->user();
        $this->login($user);
        $this->updateCurrentSession($user, ['last_seen_at' => now()->subMinutes(481)]);
        $this->getJson('/api/v1/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Please sign in again.');

        $this->login($user, remember: true);
        $this->updateCurrentSession($user, [
            'created_at' => now()->subMinutes(43201),
            'last_seen_at' => now(),
        ]);
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();

        $this->login($user);
        $this->asDatabaseUser($user, function () use ($user): void {
            UserSession::query()->where('user_id', $user->getKey())->delete();
        });
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();

        $this->login($user);
        $this->updateCurrentSession($user, [
            'created_at' => now()->subMinutes(43199),
            'last_seen_at' => now()->subMinutes(479),
        ]);
        $this->postJson('/api/v1/invitations/accept', [])
            ->assertUnprocessable()->assertJsonValidationErrors('invitation_token');
        $lastSeenAt = $this->asDatabaseUser(
            $user,
            fn () => UserSession::query()->where('user_id', $user->getKey())->sole()->last_seen_at,
        );
        $this->assertTrue($lastSeenAt->gte(now()->subSecond()));
    }

    public function test_session_inventory_prunes_expired_and_missing_backing_rows_without_touching_other_users(): void
    {
        $this->enableDatabaseSessions();
        $user = $this->user();
        $otherUser = $this->user();
        $this->login($user);
        $active = $this->trackedSession($user, 'active-other-session');
        $idle = $this->trackedSession(
            $user,
            'idle-session',
            lastSeenAt: now()->subMinutes(481),
        );
        $missingBacking = $this->trackedSession($user, 'missing-backing-session');
        DB::table('sessions')->where('id', 'missing-backing-session')->delete();
        $unrelated = $this->trackedSession(
            $otherUser,
            'unrelated-idle-session',
            lastSeenAt: now()->subMinutes(481),
        );

        $this->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $active->getKey()]);
        $this->asDatabaseUser($user, function () use ($idle, $missingBacking): void {
            $this->assertDatabaseMissing('user_sessions', ['id' => $idle->getKey()]);
            $this->assertDatabaseMissing('user_sessions', ['id' => $missingBacking->getKey()]);
        });
        $this->asDatabaseUser($otherUser, fn () => $this->assertDatabaseHas('user_sessions', [
            'id' => $unrelated->getKey(),
        ]));
    }

    public function test_filament_panel_runs_the_same_database_session_lifetime_registry(): void
    {
        $this->enableDatabaseSessions();
        [$user, $studio] = $this->owner();
        $middleware = Filament::getPanel('admin')->getMiddleware();
        $startSessionPosition = array_search(StartSession::class, $middleware, true);
        $lifetimePosition = array_search(EnforceBrowserSessionLifetime::class, $middleware, true);
        $this->assertIsInt($startSessionPosition);
        $this->assertIsInt($lifetimePosition);
        $this->assertGreaterThan($startSessionPosition, $lifetimePosition);

        $this->login($user);
        $this->updateCurrentSession($user, ['last_seen_at' => now()->subMinutes(479)]);
        $this->get("/manage/studio/{$studio->slug}")->assertOk();
        $lastSeenAt = $this->asDatabaseUser(
            $user,
            fn () => UserSession::query()->where('user_id', $user->getKey())->sole()->last_seen_at,
        );
        $this->assertTrue($lastSeenAt->gte(now()->subSecond()));
    }

    public function test_filament_login_registers_the_new_database_session_before_the_first_authenticated_request(): void
    {
        $this->enableDatabaseSessions();
        [$user, $studio] = $this->owner();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(FilamentLogin::class)
            ->fillForm([
                'email' => strtoupper($user->email),
                'password' => self::PASSWORD,
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $sessionId = session()->getId();
        $this->asDatabaseUser($user, fn () => $this->assertDatabaseHas('user_sessions', [
            'session_id' => $sessionId,
            'user_id' => $user->getKey(),
        ]));
        $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get("/manage/studio/{$studio->slug}")
            ->assertOk();
    }

    public function test_filament_login_uses_the_shared_keyed_account_and_ip_limits(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $studio = Studio::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $accountKey = app(LoginRateLimitKey::class)->for($user->email, '127.0.0.1');
        $ipKey = 'login-ip|127.0.0.1';

        Livewire::test(FilamentLogin::class)
            ->fillForm([
                'email' => strtoupper($user->email),
                'password' => 'wrong-password',
                'remember' => false,
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertSame(1, RateLimiter::attempts($accountKey));
        $this->assertSame(1, RateLimiter::attempts($ipKey));
    }

    public function test_sensitive_limiter_windows_and_keys_do_not_contain_raw_session_user_or_ip_identifiers(): void
    {
        $request = Request::create(
            '/api/v1/auth/two-factor-challenge',
            'POST',
            server: ['REMOTE_ADDR' => '198.51.100.77'],
        );
        $request->setLaravelSession(app('session')->driver());
        $request->session()->setId('session-marker-should-not-leak');
        $request->session()->put('login.id', 'user-marker-987654321');

        $twoFactorLimits = RateLimiter::limiter('two-factor')($request);
        $passkeyLimits = RateLimiter::limiter('passkeys')($request);
        $this->assertSame([5, 20], array_column($twoFactorLimits, 'maxAttempts'));
        $this->assertSame([300, 3600], array_column($twoFactorLimits, 'decaySeconds'));
        $this->assertSame([10, 50], array_column($passkeyLimits, 'maxAttempts'));
        $this->assertSame([300, 3600], array_column($passkeyLimits, 'decaySeconds'));

        foreach ([...$twoFactorLimits, ...$passkeyLimits] as $limit) {
            $this->assertStringNotContainsString('user-marker-987654321', $limit->key);
            $this->assertStringNotContainsString('session-marker-should-not-leak', $limit->key);
            $this->assertStringNotContainsString('198.51.100.77', $limit->key);
            $this->assertMatchesRegularExpression('/^[a-z-]+\|[a-f0-9]{64}$/', $limit->key);
        }
    }

    public function test_production_security_configuration_requires_bounded_limits_database_sessions_and_dedicated_passkey_secret(): void
    {
        config([
            'auth.password_timeout' => 601,
            'session.driver' => 'database',
            'mail.default' => 'array',
            'fortify.passkeys.user_handle_secret' => config('app.key'),
            'services.invitations.token_secret' => str_repeat('i', 32),
            'fortify.passkeys.relying_party_id' => 'app.example.com',
            'fortify.passkeys.allowed_origins' => ['https://app.example.com'],
        ]);

        $method = new \ReflectionMethod(AppServiceProvider::class, 'assertProductionSecurityLimits');

        try {
            $method->invoke(new AppServiceProvider($this->app));
            $this->fail('Invalid production security settings were accepted.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(LogicException::class, $exception);
            $this->assertStringContainsString('AUTH_PASSWORD_TIMEOUT', $exception->getMessage());
        }

        config([
            'auth.password_timeout' => 600,
            'fortify.passkeys.user_handle_secret' => str_repeat('p', 32),
        ]);
        $method->invoke(new AppServiceProvider($this->app));
        $this->addToAssertionCount(1);

        foreach (['', (string) config('app.key'), 'too-short'] as $invalidSecret) {
            config(['services.invitations.token_secret' => $invalidSecret]);

            try {
                $method->invoke(new AppServiceProvider($this->app));
                $this->fail('An invalid invitation secret was accepted.');
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(LogicException::class, $exception);
                $this->assertStringContainsString('INVITATION_TOKEN_SECRET', $exception->getMessage());
            }
        }

        config(['services.invitations.token_secret' => str_repeat('i', 32)]);

        config(['mail.default' => 'log']);

        try {
            $method->invoke(new AppServiceProvider($this->app));
            $this->fail('The log mailer was accepted for production invitation delivery.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(LogicException::class, $exception);
            $this->assertStringContainsString('MAIL_MAILER', $exception->getMessage());
        }
    }

    /** @return array{User, string} */
    private function twoFactorUser(array $recoveryCodes = ['first-recovery', 'second-recovery']): array
    {
        $user = $this->user();
        $secret = app(TwoFactorAuthenticationProvider::class)->generateSecretKey(32);
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user, $secret];
    }

    private function user(): User
    {
        return User::factory()->create(['password' => self::PASSWORD]);
    }

    private function enableDatabaseSessions(): void
    {
        config([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'security.session_idle_minutes' => 480,
            'security.session_absolute_minutes' => 43200,
        ]);
        /** @var SessionManager $manager */
        $manager = app('session');
        $manager->forgetDrivers();
    }

    private function login(User $user, bool $remember = false): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'remember' => $remember,
        ])->assertOk()->assertJsonPath('two_factor', false);
        $cookieName = (string) config('session.cookie');
        $sessionCookie = $response->getCookie($cookieName);
        $this->assertNotNull($sessionCookie);
        $this->withCookie($cookieName, $sessionCookie->getValue());
        $this->assertAuthenticatedAs($user);
        Auth::forgetGuards();
    }

    private function trackedSession(
        User $user,
        string $sessionId,
        ?\DateTimeInterface $createdAt = null,
        ?\DateTimeInterface $lastSeenAt = null,
    ): UserSession {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.25',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
            'payload' => base64_encode('test-session'),
            'last_activity' => now()->timestamp,
        ]);

        return $this->asDatabaseUser($user, fn () => UserSession::query()->create([
            'id' => (string) Str::ulid(),
            'session_id' => $sessionId,
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.25',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
            'created_at' => $createdAt ?? now(),
            'last_seen_at' => $lastSeenAt ?? now(),
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function updateCurrentSession(User $user, array $attributes): void
    {
        $this->asDatabaseUser($user, function () use ($user, $attributes): void {
            UserSession::query()
                ->where('user_id', $user->getKey())
                ->where('session_id', session()->getId())
                ->sole()
                ->forceFill($attributes)
                ->save();
        });
    }

    private function asDatabaseUser(User $user, Closure $callback): mixed
    {
        $context = app(RequestDatabaseContext::class);
        $context->activateUser($user);

        try {
            return $callback();
        } finally {
            $context->clearUser();
        }
    }

    /** @return array{User, Studio} */
    private function owner(): array
    {
        $user = $this->user();
        $studio = Studio::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $user->getKey(),
            'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);

        return [$user, $studio];
    }
}
