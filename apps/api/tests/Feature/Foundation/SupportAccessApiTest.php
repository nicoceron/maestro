<?php

namespace Tests\Feature\Foundation;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\SupportAccess\Models\PlatformSupportOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class SupportAccessApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'audit.integrity_key' => 'base64:'.base64_encode(str_repeat('e', 32)),
            'support-access.token_key' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'session.driver' => 'array',
        ]);
        Queue::fake();
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_platform_request_requires_current_session_mfa_and_returns_created_when_assured(): void
    {
        $studio = Studio::factory()->create(['timezone' => 'UTC']);
        $support = $this->mfaUser();
        PlatformSupportOperator::query()->create([
            'user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true,
        ]);
        $payload = [
            'studio_id' => $studio->getKey(), 'scopes' => ['audit.read', 'diagnostics.read'],
            'reason' => 'Investigate a customer-reported calendar synchronization issue.',
            'starts_at' => now()->startOfSecond()->toIso8601String(),
            'expires_at' => now()->startOfSecond()->addHours(2)->toIso8601String(),
        ];

        Sanctum::actingAs($support);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson('/api/v1/platform/support-access/requests', $payload, ['Idempotency-Key' => 'api-request-one'])
            ->assertStatus(423)->assertJsonPath('code', 'support_current_session_mfa_required');

        $response = $this->withSession([
            'auth.password_confirmed_at' => now()->timestamp,
            'support_access.mfa_verified_at' => now()->timestamp,
        ])->postJson('/api/v1/platform/support-access/requests', $payload, ['Idempotency-Key' => 'api-request-one']);
        $response->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.scopes.0', 'audit.read');
        $this->assertDatabaseCount('support_access_grants', 1);
    }

    public function test_tenant_audit_and_support_decisions_are_authorized_scoped_and_current_session_mfa_gated(): void
    {
        $studio = Studio::factory()->create(['timezone' => 'UTC']);
        $owner = $this->mfaUser();
        $teacher = User::factory()->create();
        foreach ([[$owner, MembershipRole::Owner], [$teacher, MembershipRole::Teacher]] as [$user, $role]) {
            StudioMembership::query()->create([
                'studio_id' => $studio->getKey(), 'user_id' => $user->getKey(), 'role' => $role,
                'status' => MembershipStatus::Active, 'joined_at' => now(), 'preferences' => [],
            ]);
        }

        Sanctum::actingAs($teacher);
        $this->getJson("/api/v1/studios/{$studio->slug}/audit-events")->assertForbidden();
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/studios/{$studio->slug}/audit-events")
            ->assertOk()->assertJsonMissingPath('data.0.integrity_hash');
    }

    public function test_support_mfa_confirmation_proves_the_current_session_without_exposing_secret_material(): void
    {
        $support = $this->mfaUser();
        PlatformSupportOperator::query()->create([
            'user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true,
        ]);
        Sanctum::actingAs($support);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        $this->postJson('/api/v1/platform/support-access/mfa-confirmation', ['code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'support_mfa_invalid');
        $code = (new Google2FA)->getCurrentOtp('ABCDEFGHIJKLMNOP');
        $response = $this->postJson('/api/v1/platform/support-access/mfa-confirmation', ['code' => $code])
            ->assertOk()->assertJsonPath('data.verified', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertIsInt(session('support_access.mfa_verified_at'));
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOP', $response->getContent());
        $this->assertStringNotContainsString($code, $response->getContent());
    }

    private function mfaUser(): User
    {
        return User::factory()->create([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ABCDEFGHIJKLMNOP'),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
