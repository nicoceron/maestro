<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HttpBoundarySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_browser_and_api_responses(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }

    public function test_missing_and_trusted_origins_work_but_untrusted_origin_or_referer_is_rejected(): void
    {
        $payload = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();

        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertUnprocessable();

        $this->withHeader('Origin', 'https://attacker.example')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Untrusted request origin.');

        $this->flushHeaders();
        $this->withHeader('Referer', 'https://attacker.example/forged')
            ->postJson('/api/v1/auth/login', $payload)
            ->assertForbidden();
    }

    public function test_untrusted_hosts_are_rejected_outside_local_and_test_environments(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            $this->get('http://attacker.example/up')->assertBadRequest();
            $this->get('http://localhost/up')->assertOk();
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }
    }
}
