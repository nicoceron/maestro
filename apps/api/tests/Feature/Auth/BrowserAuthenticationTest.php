<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\QueuedResetPasswordNotification;
use App\Support\Auth\LoginRateLimitKey;
use App\Support\Auth\PasswordResetRateLimitKey;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrowserAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-42!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();
    }

    public function test_browser_can_initialize_csrf_protection(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_registration_normalizes_email_rotates_the_session_and_sends_verification(): void
    {
        Notification::fake();
        $this->withSession(['before' => true]);
        $oldSessionId = session()->getId();

        $this->postJson('/api/v1/auth/register', [
            'name' => '  Ada Lovelace  ',
            'email' => '  ADA@Example.COM ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertCreated();

        $user = User::query()->sole();
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_enforces_a_strong_confirmed_password_and_generic_duplicate_error(): void
    {
        User::factory()->create(['email' => 'member@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('errors.email.0', 'We could not create an account with those details.');
    }

    public function test_registration_rejects_a_password_reported_as_compromised(): void
    {
        $this->app->instance(
            UncompromisedVerifier::class,
            new class implements UncompromisedVerifier
            {
                public function verify($data): bool
                {
                    return false;
                }
            },
        );

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Compromised Password',
            'email' => 'compromised@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'compromised@example.com']);
    }

    public function test_email_normalization_handles_unicode_whitespace_compatibility_and_case(): void
    {
        $this->assertSame(
            'member@example.com',
            User::normalizeEmail("\u{00A0}ＭＥＭＢＥＲ＠ＥＸＡＭＰＬＥ．ＣＯＭ\u{2003}"),
        );

        $user = User::factory()->create([
            'email' => "\u{2002}Ｍｅｍｂｅｒ＠Ｅｘａｍｐｌｅ．Ｃｏｍ\u{00A0}",
        ]);
        $this->assertSame('member@example.com', $user->email);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Member',
            'email' => "\u{2003}MEMBER@EXAMPLE.COM\u{00A0}",
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'We could not create an account with those details.');
    }

    public function test_postgresql_normalizes_direct_email_writes_and_rejects_equivalent_variants(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific normalized email constraint.');
        }

        $attributes = [
            'name' => 'Direct SQL User',
            'password' => Hash::make(self::PASSWORD),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('users')->insert([
            ...$attributes,
            'email' => "\u{00A0}ＤＩＲＥＣＴ＠ＥＸＡＭＰＬＥ．ＣＯＭ\u{2003}",
        ]);
        $this->assertDatabaseHas('users', ['email' => 'direct@example.com']);

        $this->expectException(QueryException::class);
        DB::table('users')->insert([
            ...$attributes,
            'name' => 'Duplicate Direct SQL User',
            'email' => 'DIRECT@example.com',
        ]);
    }

    public function test_login_limiter_key_uses_only_a_keyed_digest_of_the_normalized_email(): void
    {
        config(['app.key' => 'base64:login-limiter-test-key']);

        $keyFactory = app(LoginRateLimitKey::class);
        $first = $keyFactory->for("\u{00A0}Private.User@Example.COM\u{2003}", '203.0.113.7');
        $second = $keyFactory->for('private.user@example.com', '203.0.113.7');

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('private.user@example.com', $first);
        $this->assertMatchesRegularExpression('/^login\|[a-f0-9]{64}\|203\.0\.113\.7$/', $first);
    }

    public function test_login_failures_use_only_hmac_and_ip_cache_keys_and_success_clears_the_account_counter(): void
    {
        config(['auth.timebox_duration' => 1]);
        $user = User::factory()->create([
            'email' => 'private.user@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => "\u{00A0}PRIVATE.USER@EXAMPLE.COM\u{2003}",
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $accountKey = app(LoginRateLimitKey::class)->for($user->email, '127.0.0.1');
        $this->assertSame(1, RateLimiter::attempts($accountKey));
        $this->assertSame(1, RateLimiter::attempts('login-ip|127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts('private.user@example.com|127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts("\u{00A0}PRIVATE.USER@EXAMPLE.COM\u{2003}|127.0.0.1"));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertSame(0, RateLimiter::attempts($accountKey));
        $this->assertSame(1, RateLimiter::attempts('login-ip|127.0.0.1'));
    }

    public function test_login_ip_spray_limit_applies_across_distinct_email_addresses(): void
    {
        config(['auth.timebox_duration' => 1]);

        foreach (range(1, 30) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "spray-{$attempt}@example.com",
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->assertSame(30, RateLimiter::attempts('login-ip|127.0.0.1'));
        $this->postJson('/api/v1/auth/login', [
            'email' => 'spray-31@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_login_rehashes_a_legacy_cost_password(): void
    {
        config(['hashing.bcrypt.rounds' => 5]);
        $legacyHash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $user = User::factory()->create([
            'email' => 'rehash@example.com',
            'password' => $legacyHash,
        ]);
        $this->assertSame(4, password_get_info($user->password)['options']['cost']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertSame(5, password_get_info($user->refresh()->password)['options']['cost']);
    }

    public function test_missing_and_wrong_user_logins_both_use_the_session_guard_timebox(): void
    {
        User::factory()->create([
            'email' => 'timed@example.com',
            'password' => self::PASSWORD,
        ]);
        Sleep::fake();

        try {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'timed@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
            $this->postJson('/api/v1/auth/login', [
                'email' => 'missing-timed@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();

            Sleep::assertSleptTimes(2);
        } finally {
            Sleep::fake(false);
        }
    }

    public function test_login_is_case_insensitive_generic_and_rate_limited(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => self::PASSWORD,
        ]);

        $existingFailure = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->json('errors.email.0');

        $missingFailure = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->json('errors.email.0');

        $this->assertSame($existingFailure, $missingFailure);

        $this->postJson('/api/v1/auth/login', [
            'email' => ' LOGIN@EXAMPLE.COM ',
            'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('two_factor', false);
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'throttled@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'throttled@example.com',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_current_user_is_safe_and_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create([
            'email' => 'session@example.com',
            'password' => self::PASSWORD,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->getJson('/api/v1/auth/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.studios');

        $oldSessionId = session()->getId();
        $oldToken = session()->token();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest();
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_password_reset_requests_do_not_enumerate_accounts_and_reset_is_strong(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => self::PASSWORD,
        ]);

        $known = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => "\u{00A0}ＲＥＳＥＴ＠ＥＸＡＭＰＬＥ．ＣＯＭ\u{2003}",
        ])->assertAccepted()->json();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'nobody@example.com',
        ])->assertAccepted()->json();
        $this->assertSame($known, $unknown);

        $token = null;
        Notification::assertSentTo(
            $user,
            QueuedResetPasswordNotification::class,
            function (QueuedResetPasswordNotification $notification) use (&$token, $user): bool {
                $token = $notification->token;
                $actionUrl = $notification->toMail($user)->actionUrl;
                parse_str((string) parse_url($actionUrl, PHP_URL_FRAGMENT), $fragment);

                return $actionUrl === 'http://localhost:3000/reset-password#'.http_build_query([
                    'token' => $token,
                    'email' => $user->email,
                ], '', '&', PHP_QUERY_RFC3986)
                    && $fragment['token'] === $token
                    && $fragment['email'] === $user->email;
            },
        );
        Notification::assertCount(1);
        $this->assertIsString($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $newPassword = 'Even-Stronger-84!';
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->assertTrue(Hash::check($newPassword, $user->refresh()->password));
    }

    public function test_password_reset_request_throttle_uses_hmac_and_ip_keys_and_stays_generic(): void
    {
        Notification::fake();
        config(['auth.timebox_duration' => 1]);
        $user = User::factory()->create(['email' => 'limited-reset@example.com']);
        $key = app(PasswordResetRateLimitKey::class)->for($user->email, '127.0.0.1');

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/auth/forgot-password', [
                'email' => "\u{00A0}LIMITED-RESET@EXAMPLE.COM\u{2003}",
            ])->assertAccepted()
                ->assertJsonPath('message', 'If an account matches that email, a password reset link will be sent.');
        }

        $this->assertSame(5, RateLimiter::attempts($key));
        $this->assertSame(5, RateLimiter::attempts('password-reset-ip|127.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts('limited-reset@example.com|127.0.0.1'));
        Notification::assertSentToTimes($user, QueuedResetPasswordNotification::class, 1);

        foreach (range(1, 25) as $attempt) {
            $this->postJson('/api/v1/auth/forgot-password', [
                'email' => "reset-spray-{$attempt}@example.com",
            ])->assertAccepted();
        }

        $this->assertSame(30, RateLimiter::attempts('password-reset-ip|127.0.0.1'));
        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'reset-spray-blocked@example.com',
        ])->assertAccepted();
        $this->assertSame(0, RateLimiter::attempts(
            app(PasswordResetRateLimitKey::class)->for('reset-spray-blocked@example.com', '127.0.0.1'),
        ));
    }

    public function test_password_reset_rotates_remember_token_and_invalidates_only_the_users_database_sessions(): void
    {
        config([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
        ]);

        $user = User::factory()->create(['remember_token' => 'known-remember-token']);
        $otherUser = User::factory()->create();
        $targetToken = $user->createToken('compromised-device')->accessToken;
        $otherToken = $otherUser->createToken('other-user-device')->accessToken;
        $sessionDefaults = [
            'ip_address' => '203.0.113.8',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('session'),
            'last_activity' => now()->timestamp,
        ];

        DB::table('sessions')->insert([
            [...$sessionDefaults, 'id' => 'target-one', 'user_id' => $user->getKey()],
            [...$sessionDefaults, 'id' => 'target-two', 'user_id' => $user->getKey()],
            [...$sessionDefaults, 'id' => 'other-user', 'user_id' => $otherUser->getKey()],
        ]);
        $databaseContext = app(RequestDatabaseContext::class);
        $databaseContext->activateUser($user);
        UserSession::query()->insert([
            [
                'id' => (string) Str::ulid(),
                'session_id' => 'target-one',
                'user_id' => $user->getKey(),
                'ip_address' => '203.0.113.8',
                'user_agent' => 'PHPUnit',
                'created_at' => now(),
                'last_seen_at' => now(),
            ],
            [
                'id' => (string) Str::ulid(),
                'session_id' => 'target-two',
                'user_id' => $user->getKey(),
                'ip_address' => '203.0.113.8',
                'user_agent' => 'PHPUnit',
                'created_at' => now(),
                'last_seen_at' => now(),
            ],
        ]);
        $databaseContext->clearUser();
        $databaseContext->activateUser($otherUser);
        $otherRegistryId = (string) Str::ulid();
        UserSession::query()->create([
            'id' => $otherRegistryId,
            'session_id' => 'other-user',
            'user_id' => $otherUser->getKey(),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
            'last_seen_at' => now(),
        ]);
        $databaseContext->clearUser();

        app(ResetUserPassword::class)->reset($user, [
            'password' => 'Replacement-Password-84!',
            'password_confirmation' => 'Replacement-Password-84!',
        ]);

        $this->assertNotSame('known-remember-token', $user->refresh()->remember_token);
        $this->assertTrue(Hash::check('Replacement-Password-84!', $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'target-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user', 'user_id' => $otherUser->getKey()]);
        $databaseContext->activateUser($user);
        $this->assertDatabaseMissing('user_sessions', ['session_id' => 'target-one']);
        $this->assertDatabaseMissing('user_sessions', ['session_id' => 'target-two']);
        $databaseContext->clearUser();
        $databaseContext->activateUser($otherUser);
        $this->assertDatabaseHas('user_sessions', ['id' => $otherRegistryId]);
        $databaseContext->clearUser();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetToken->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->getKey()]);
    }

    public function test_email_verification_and_resend_endpoints_work_for_the_session_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();
        $verificationUrl = null;
        Notification::assertSentTo(
            $user,
            VerifyEmail::class,
            function (VerifyEmail $notification, array $channels, User $notifiable) use (&$verificationUrl): bool {
                $verificationUrl = $notification->toMail($notifiable)->actionUrl;

                return is_string($verificationUrl)
                    && str_contains($verificationUrl, '/api/v1/auth/email/verify/');
            },
        );
        $this->assertIsString($verificationUrl);

        $this->get($verificationUrl, ['Accept' => 'text/html'])
            ->assertRedirect('http://localhost:3000/onboarding?verified=1');
        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_unverified_users_can_read_their_identity_but_not_enter_a_studio(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->getJson('/api/v1/auth/user')->assertOk();
        $this->getJson('/api/v1/studios')->assertForbidden()
            ->assertJsonPath('message', 'Your email address is not verified.');
    }

    public function test_auth_cors_is_credentialed_only_for_configured_origins(): void
    {
        $headers = [
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type,X-XSRF-TOKEN',
        ];

        $this->call('OPTIONS', '/api/v1/auth/login', server: $this->transformHeadersToServerVars($headers))
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $untrusted = $this->call('OPTIONS', '/api/v1/auth/login', server: $this->transformHeadersToServerVars([
            ...$headers,
            'Origin' => 'https://attacker.example',
        ]));
        $this->assertNotSame('https://attacker.example', $untrusted->headers->get('Access-Control-Allow-Origin'));
    }
}
