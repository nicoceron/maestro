<?php

namespace Tests\Feature\Api;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_studios(): void
    {
        $this->getJson('/api/v1/studios')->assertUnauthorized();
    }

    public function test_api_cors_allows_only_the_configured_credentialed_frontend(): void
    {
        $preflightHeaders = [
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'GET',
        ];

        $this->call('OPTIONS', '/api/v1/studios', server: $this->transformHeadersToServerVars(
            $preflightHeaders,
        ))
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $untrustedResponse = $this->call(
            'OPTIONS',
            '/api/v1/studios',
            server: $this->transformHeadersToServerVars([
                ...$preflightHeaders,
                'Origin' => 'https://untrusted.example',
            ]),
        );

        $untrustedResponse
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $this->assertNotSame(
            'https://untrusted.example',
            $untrustedResponse->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_members_only_see_their_active_studios(): void
    {
        $user = User::factory()->create();
        $managed = Studio::factory()->create(['name' => 'Aria Academy']);
        $teaching = Studio::factory()->create(['name' => 'Bell Music']);
        $suspended = Studio::factory()->create(['name' => 'Cadence Lab']);
        Studio::factory()->create(['name' => 'Different Tenant']);

        $this->membership($user, $managed, MembershipRole::Owner);
        $this->membership($user, $teaching, MembershipRole::Teacher);
        $this->membership($user, $suspended, MembershipRole::Administrator, MembershipStatus::Suspended);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/studios')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Aria Academy')
            ->assertJsonPath('data.0.membership.role', 'owner')
            ->assertJsonPath('data.0.permissions.manage', true)
            ->assertJsonPath('data.1.name', 'Bell Music')
            ->assertJsonPath('data.1.permissions.manage', false);
    }

    public function test_authenticated_user_can_create_a_studio_and_becomes_its_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/studios', [
            'name' => 'Sonora House',
            'timezone' => 'America/Bogota',
            'locale' => 'es',
            'currency' => 'cop',
            'week_starts_on' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sonora House')
            ->assertJsonPath('data.slug', 'sonora-house')
            ->assertJsonPath('data.status', 'trial')
            ->assertJsonPath('data.currency', 'COP')
            ->assertJsonPath('data.membership.role', 'owner')
            ->assertJsonPath('data.permissions.manage', true);

        $studioId = $response->json('data.id');
        $this->assertDatabaseHas('studio_memberships', [
            'studio_id' => $studioId,
            'user_id' => $user->getKey(),
            'role' => MembershipRole::Owner->value,
            'status' => MembershipStatus::Active->value,
        ]);
    }

    public function test_generated_studio_slugs_are_collision_safe(): void
    {
        Studio::factory()->create(['slug' => 'sonora-house']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/studios', ['name' => 'Sonora House'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'sonora-house-2');
    }

    public function test_reserved_or_invalid_studio_settings_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/studios', [
            'name' => 'Unsafe',
            'slug' => 'admin',
            'locale' => 'xx',
            'currency' => '12',
        ])->assertUnprocessable()->assertJsonValidationErrors(['slug', 'locale', 'currency']);
    }

    public function test_cross_tenant_studio_access_is_denied(): void
    {
        $user = User::factory()->create();
        $ownStudio = Studio::factory()->create();
        $otherStudio = Studio::factory()->create();
        $this->membership($user, $ownStudio, MembershipRole::Teacher);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/studios/'.$ownStudio->slug)
            ->assertOk()
            ->assertJsonPath('data.permissions.manage', false);

        $this->getJson('/api/v1/studios/'.$otherStudio->slug)->assertForbidden();
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
