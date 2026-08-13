<?php

namespace Tests\Feature\Security;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\SupportAccess\Models\PlatformSupportOperator;
use App\SupportAccess\SupportSessionToken;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditOutboxSupportPostgresTest extends TestCase
{
    public function test_postgres_serializes_concurrent_audit_writers_into_one_unbroken_hash_chain(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the audit concurrency test.');
        }
        [$studio] = $this->tenant();
        $studioId = (string) $studio->getKey();
        $tasks = [];
        foreach (range(1, 4) as $number) {
            $tasks[] = static fn (): int => app(AuditWriter::class)->record(new AuditRecord(
                studioId: $studioId,
                eventType: 'fixture.concurrent',
                subjectType: 'fixture',
                subjectId: "concurrent-{$number}",
                payload: SafeAuditPayload::from(['worker' => $number], ['worker']),
                actorType: 'system',
                actorDisplay: 'Concurrency test',
            ))->stream_sequence;
        }

        $sequences = Concurrency::driver('process')->run($tasks, 30);
        sort($sequences);
        $this->assertSame([2, 3, 4, 5], $sequences);
        $events = DB::table('tenant_audit_events')->where('studio_id', $studioId)->orderBy('stream_sequence')->get();
        $this->assertCount(5, $events);
        foreach ($events->skip(1) as $index => $event) {
            $this->assertSame($events[$index - 1]->integrity_hash, $event->previous_hash);
        }
    }

    public function test_postgres_forces_rls_default_denies_cross_tenant_and_database_rejects_immutable_mutation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the platform operations RLS test.');
        }
        [$first, $firstOwner] = $this->tenant();
        [$second] = $this->tenant();

        foreach (['tenant_audit_events', 'transactional_outbox_messages', 'support_access_grants', 'support_access_sessions'] as $table) {
            $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);
            $this->assertTrue((bool) $security->relrowsecurity, "{$table} must enable RLS.");
            $this->assertTrue((bool) $security->relforcerowsecurity, "{$table} must force RLS.");
        }

        [$runtime, $runtimeName] = $this->runtimeConnection();
        try {
            $runtime->statement("select set_config('app.current_user_id', ?, false)", [(string) $firstOwner->getKey()]);
            $runtime->statement("select set_config('app.current_studio_id', '', false)");
            $this->assertSame(0, $runtime->table('tenant_audit_events')->count());
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$first->getKey()]);
            $this->assertSame(1, $runtime->table('tenant_audit_events')->count());
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$second->getKey()]);
            $this->assertSame(1, $runtime->table('tenant_audit_events')->count());
            $this->assertFalse($runtime->table('tenant_audit_events')->where('studio_id', $first->getKey())->exists());

            $eventId = DB::table('tenant_audit_events')->where('studio_id', $first->getKey())->value('id');
            $this->assertSame(0, $runtime->table('tenant_audit_events')->where('id', $eventId)->update(['event_type' => 'tampered']));
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$first->getKey()]);
            $this->assertDenied(fn () => $runtime->table('tenant_audit_events')->where('id', $eventId)->update(['event_type' => 'tampered']));
            $this->assertDenied(fn () => $runtime->table('tenant_audit_events')->where('id', $eventId)->delete());
        } finally {
            DB::purge($runtimeName);
        }
    }

    public function test_postgres_support_session_rls_requires_bound_active_session_and_scope(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for the support session RLS test.');
        }
        [$studio, $owner] = $this->tenant();
        $support = User::factory()->create(['two_factor_confirmed_at' => now()]);
        PlatformSupportOperator::query()->create(['user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true]);
        $token = app(SupportSessionToken::class)->issue();
        $grantId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();
        DB::table('support_access_grants')->insert([
            'id' => $grantId, 'studio_id' => $studio->getKey(), 'requested_by_user_id' => $support->getKey(),
            'approved_by_user_id' => $owner->getKey(), 'idempotency_key' => 'pg-support', 'scopes' => json_encode(['audit.read']),
            'reason' => 'Review the audit trail for PostgreSQL isolation evidence.', 'status' => 'approved',
            'starts_at' => now()->subMinute(), 'expires_at' => now()->addHour(), 'approved_at' => now(),
            'version' => 2, 'correlation_id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('support_access_sessions')->insert([
            'id' => $sessionId, 'studio_id' => $studio->getKey(), 'grant_id' => $grantId,
            'support_user_id' => $support->getKey(), 'approved_by_user_id' => $owner->getKey(),
            'scopes' => json_encode(['audit.read']), 'reason' => 'Review the audit trail for PostgreSQL isolation evidence.',
            'token_hash' => $token['hash'], 'recent_auth_at' => now(), 'mfa_verified_at' => now(),
            'started_at' => now(), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$runtime, $runtimeName] = $this->runtimeConnection();
        try {
            $runtime->statement("select set_config('app.current_user_id', ?, false)", [(string) $support->getKey()]);
            $runtime->statement("select set_config('app.current_studio_id', '', false)");
            $runtime->statement("select set_config('app.current_support_session_id', '', false)");
            $this->assertSame(0, $runtime->table('tenant_audit_events')->count());
            $runtime->statement("select set_config('app.current_support_session_id', ?, false)", [$sessionId]);
            $this->assertSame(1, $runtime->table('tenant_audit_events')->count());
            $this->assertSame(0, $runtime->table('tenant_audit_streams')->count());
            $this->assertSame(0, $runtime->table('transactional_outbox_messages')->count());
            $this->assertSame(0, $runtime->table('support_access_sessions')
                ->where('id', $sessionId)
                ->update(['ended_at' => now(), 'end_reason' => 'operator_ended']));
            $this->assertDenied(fn () => $runtime->table('tenant_audit_events')->insert([
                'id' => (string) Str::ulid(),
                'studio_id' => $studio->getKey(),
                'stream_sequence' => 99,
                'event_type' => 'support.bypass',
                'subject_type' => 'fixture',
                'subject_id' => 'forbidden',
                'actor_type' => 'support',
                'actor_display' => 'Forbidden support write',
                'correlation_id' => (string) Str::ulid(),
                'payload_version' => 1,
                'payload' => '{}',
                'integrity_hash' => str_repeat('b', 64),
                'occurred_at' => now(),
            ]));
            $runtime->statement("select set_config('app.current_studio_id', ?, false)", [$studio->getKey()]);
            $this->assertSame(1, $runtime->table('support_access_sessions')
                ->where('id', $sessionId)
                ->update(['ended_at' => now(), 'end_reason' => 'operator_ended']));
            $runtime->statement("select set_config('app.current_studio_id', '', false)");
            $this->assertSame(0, $runtime->table('tenant_audit_events')->count());
        } finally {
            DB::purge($runtimeName);
        }
    }

    /** @return array{Studio, User} */
    private function tenant(): array
    {
        $studio = Studio::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->create();
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $owner->getKey(), 'role' => MembershipRole::Owner,
            'status' => MembershipStatus::Active, 'joined_at' => now(), 'preferences' => [],
        ]);
        DB::table('tenant_audit_streams')->insert([
            'studio_id' => $studio->getKey(), 'last_sequence' => 1, 'last_hash' => str_repeat('a', 64), 'updated_at' => now(),
        ]);
        DB::table('tenant_audit_events')->insert([
            'id' => (string) Str::ulid(), 'studio_id' => $studio->getKey(), 'stream_sequence' => 1,
            'event_type' => 'fixture.created', 'subject_type' => 'fixture', 'subject_id' => 'fixture',
            'actor_type' => 'user', 'actor_user_id' => $owner->getKey(), 'actor_display' => 'Studio owner',
            'correlation_id' => (string) Str::ulid(), 'payload_version' => 1, 'payload' => json_encode(['state' => 'created']),
            'integrity_hash' => str_repeat('a', 64), 'occurred_at' => now(),
        ]);

        return [$studio, $owner];
    }

    private function assertDenied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL accepted a forbidden operation.');
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression('/row-level security|immutable|permission denied/i', $exception->getMessage());
        }
    }

    /** @return array{ConnectionInterface, string} */
    private function runtimeConnection(): array
    {
        $username = (string) env('DB_RUNTIME_USERNAME');
        $password = (string) env('DB_RUNTIME_PASSWORD');
        if ($username === '' || $password === '') {
            $this->markTestSkipped('A restricted PostgreSQL runtime role was not configured.');
        }
        $name = 'pgsql_runtime_platform_ops_'.Str::lower((string) Str::ulid());
        config(["database.connections.{$name}" => array_replace(
            config('database.connections.pgsql'), ['username' => $username, 'password' => $password],
        )]);
        DB::purge($name);

        return [DB::connection($name), $name];
    }
}
