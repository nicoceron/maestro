<?php

namespace Tests\Feature\Foundation;

use App\Audit\AuditIntegrityVerifier;
use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\Http\Resources\TenantAuditEventResource;
use App\Audit\Models\TenantAuditEvent;
use App\Audit\SafeAuditPayload;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Outbox\Events\OutboxMessagePublished;
use App\Outbox\Jobs\ProcessOutboxMessage;
use App\Outbox\Models\OutboxMessage;
use App\Outbox\OutboxIdempotencyConflict;
use App\Outbox\OutboxProcessor;
use App\Outbox\OutboxRecord;
use App\Outbox\OutboxWriter;
use App\SupportAccess\Actions\ApproveSupportAccess;
use App\SupportAccess\Actions\EndSupportSession;
use App\SupportAccess\Actions\RequestSupportAccess;
use App\SupportAccess\Actions\RevokeSupportAccess;
use App\SupportAccess\Actions\StartSupportSession;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Http\Resources\SupportSessionBannerResource;
use App\SupportAccess\Models\PlatformSupportOperator;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class AuditOutboxSupportAccessTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'audit.integrity_key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'support-access.token_key' => 'base64:'.base64_encode(str_repeat('c', 32)),
        ]);
    }

    public function test_audit_payload_requires_declared_keys_and_redacts_secret_keys_and_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SafeAuditPayload::from(['allowed' => true, 'unknown' => 'value'], ['allowed']);
    }

    public function test_audit_payload_redacts_secrets_even_when_the_key_looks_benign(): void
    {
        $payload = SafeAuditPayload::from([
            'token' => 'top-secret',
            'note' => 'Bearer plaintextToken-for-audit-leak-proof',
            'safe_id' => (string) Str::ulid(),
        ], ['token', 'note', 'safe_id'])->jsonSerialize();

        $this->assertSame('[redacted]', $payload['token']);
        $this->assertSame('[redacted]', $payload['note']);
        $this->assertNotSame('[redacted]', $payload['safe_id']);
    }

    public function test_audit_stream_is_hash_chained_verifiable_and_database_immutable(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $writer = app(AuditWriter::class);
        $first = $writer->record(new AuditRecord(
            studioId: (string) $studio->getKey(), eventType: 'test.created', subjectType: 'fixture',
            subjectId: (string) Str::ulid(), payload: SafeAuditPayload::from(['state' => 'created'], ['state']), actor: $owner,
        ));
        $second = $writer->record(new AuditRecord(
            studioId: (string) $studio->getKey(), eventType: 'test.updated', subjectType: 'fixture',
            subjectId: (string) Str::ulid(), payload: SafeAuditPayload::from(['state' => 'updated'], ['state']), actor: $owner,
            correlationId: $first->correlation_id, causationId: (string) $first->getKey(),
        ));

        $this->assertSame(1, $first->stream_sequence);
        $this->assertSame(2, $second->stream_sequence);
        $this->assertSame($first->integrity_hash, $second->previous_hash);
        $this->assertSame(['valid' => true, 'checked' => 2, 'failed_event_id' => null], app(AuditIntegrityVerifier::class)->verify((string) $studio->getKey()));
        $this->assertMutationRejected(fn () => DB::table('tenant_audit_events')->where('id', $first->getKey())->update(['event_type' => 'tampered']));
        $this->assertMutationRejected(fn () => DB::table('tenant_audit_events')->where('id', $first->getKey())->delete());
    }

    public function test_public_audit_resource_omits_internal_identity_network_and_integrity_fields(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $event = app(AuditWriter::class)->record(new AuditRecord(
            studioId: (string) $studio->getKey(), eventType: 'safe.visible', subjectType: 'fixture', subjectId: 'subject',
            payload: SafeAuditPayload::from(['state' => 'safe'], ['state']), actor: $owner,
        ));
        $json = (new TenantAuditEventResource($event))->resolve(request());
        $encoded = json_encode($json, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('actor_user_id', $json);
        $this->assertArrayNotHasKey('request_ip_hash', $json);
        $this->assertArrayNotHasKey('user_agent_hash', $json);
        $this->assertArrayNotHasKey('integrity_hash', $json);
        $this->assertArrayNotHasKey('support_session_id', $json);
        $this->assertStringNotContainsString($owner->email, $encoded);
    }

    public function test_outbox_requires_a_transaction_is_exactly_idempotent_and_dispatches_after_commit(): void
    {
        [, $studio] = $this->ownerFixture();
        Queue::fake();
        $record = new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.changed', aggregateType: 'fixture', aggregateId: 'one',
            idempotencyKey: 'fixture-once', payload: ['state' => 'ready'], correlationId: (string) Str::ulid(),
        );

        try {
            app(OutboxWriter::class)->record($record);
            $this->fail('Outbox write outside a transaction was accepted.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('transactional_outbox_messages', 0);
        }

        DB::beginTransaction();
        $first = app(OutboxWriter::class)->record($record);
        $replay = app(OutboxWriter::class)->record($record);
        $this->assertSame($first->getKey(), $replay->getKey());
        Queue::assertPushed(ProcessOutboxMessage::class, fn (ProcessOutboxMessage $job): bool => $job->afterCommit === true);
        DB::commit();
        Queue::assertPushed(ProcessOutboxMessage::class, 1);
        $this->assertSame(1, $first->aggregate_sequence);

        DB::transaction(fn () => app(OutboxWriter::class)->record(new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.changed', aggregateType: 'fixture', aggregateId: 'one',
            idempotencyKey: 'fixture-two', payload: ['state' => 'changed'],
        )));
        $this->assertSame([1, 2], OutboxMessage::query()->orderBy('aggregate_sequence')->pluck('aggregate_sequence')->all());

        $this->expectException(OutboxIdempotencyConflict::class);
        DB::transaction(fn () => app(OutboxWriter::class)->record(new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.changed', aggregateType: 'fixture', aggregateId: 'one',
            idempotencyKey: 'fixture-once', payload: ['state' => 'different'],
        )));
    }

    public function test_outbox_claim_publishes_once_and_recovers_an_expired_lease(): void
    {
        [, $studio] = $this->ownerFixture();
        Queue::fake();
        $message = DB::transaction(fn () => app(OutboxWriter::class)->record(new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.publish', aggregateType: 'fixture', aggregateId: 'publish',
            idempotencyKey: 'publish-once', payload: ['state' => 'published'],
        )));
        Event::fake([OutboxMessagePublished::class]);

        $this->assertTrue(app(OutboxProcessor::class)->process($message->studio_id, (string) $message->getKey(), 'worker-a'));
        $this->assertFalse(app(OutboxProcessor::class)->process($message->studio_id, (string) $message->getKey(), 'worker-b'));
        Event::assertDispatched(OutboxMessagePublished::class, 1);
        $message->refresh();
        $this->assertSame('processed', $message->status);
        $this->assertNotNull($message->published_at);
        $this->assertNull($message->claim_token);

        $stale = DB::transaction(fn () => app(OutboxWriter::class)->record(new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.recover', aggregateType: 'fixture', aggregateId: 'recover',
            idempotencyKey: 'recover-expired', payload: ['state' => 'ready'],
        )));
        $stale->forceFill(['status' => 'processing', 'claimed_at' => now()->subMinutes(5), 'claimed_by' => 'dead-worker',
            'claim_token' => (string) Str::ulid(), 'claim_expires_at' => now()->subMinute(), 'attempts' => 1])->save();
        $this->assertTrue(app(OutboxProcessor::class)->process($stale->studio_id, (string) $stale->getKey(), 'recovery-worker'));
        $this->assertSame('processed', $stale->refresh()->status);
        $this->assertSame(2, $stale->attempts);
    }

    public function test_outbox_failure_is_sanitized_and_dead_lettered_without_leaking_exception_text(): void
    {
        [, $studio] = $this->ownerFixture();
        Queue::fake();
        $message = DB::transaction(fn () => app(OutboxWriter::class)->record(new OutboxRecord(
            studioId: (string) $studio->getKey(), topic: 'fixture.fail', aggregateType: 'fixture', aggregateId: 'fail',
            idempotencyKey: 'fail-once', payload: ['state' => 'ready'], maxAttempts: 1,
        )));
        Event::listen(OutboxMessagePublished::class, fn () => throw new RuntimeException('plaintextToken-never-store-this'));

        app(OutboxProcessor::class)->process($message->studio_id, (string) $message->getKey(), 'worker');
        $message->refresh();
        $this->assertSame('dead_letter', $message->status);
        $this->assertSame('handler_exception', $message->last_error_code);
        $this->assertSame('RuntimeException', $message->last_error_summary);
        $this->assertStringNotContainsString('plaintextToken-never-store-this', $message->toJson());
    }

    public function test_support_access_requires_studio_approval_and_mfa_then_issues_a_response_once_token(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $support = User::factory()->create(['two_factor_confirmed_at' => now()]);
        PlatformSupportOperator::query()->create(['user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true]);
        Queue::fake();
        $starts = now()->startOfSecond()->toImmutable();
        $grant = app(RequestSupportAccess::class)->handle(
            $support, $studio, [SupportScope::AuditRead->value, SupportScope::DiagnosticsRead->value],
            'Investigate a reported calendar synchronization failure.', $starts, $starts->addHours(4), 'support-request-one',
        );
        $this->assertSame(GrantStatus::Requested, $grant->status);
        $this->assertDatabaseCount('support_access_sessions', 0);

        try {
            app(StartSupportSession::class)->handle($support, $grant, now()->toImmutable());
            $this->fail('An unapproved support grant started a session.');
        } catch (SupportAccessException $exception) {
            $this->assertSame('support_grant_inactive', $exception->codeName);
        }

        $grant = app(ApproveSupportAccess::class)->handle($owner, $grant, 1);
        $started = app(StartSupportSession::class)->handle($support, $grant, now()->toImmutable());
        $session = $started['session'];
        $plain = $started['access_token'];
        $this->assertSame(43, strlen($plain));
        $this->assertNotSame($plain, $session->token_hash);
        $this->assertStringNotContainsString($plain, $session->toJson());
        $this->assertStringNotContainsString($plain, TenantAuditEvent::query()->get()->toJson());
        $banner = (new SupportSessionBannerResource($session->load(['studio', 'approver'])))->resolve(request());
        $this->assertStringContainsString('visible', $banner['banner']);
        $this->assertArrayNotHasKey('token_hash', $banner);
        $this->assertArrayNotHasKey('access_token', $banner);

        $grant = app(RevokeSupportAccess::class)->handle($owner, $grant, 2, 'The requested diagnostic work is complete.');
        $this->assertSame(GrantStatus::Revoked, $grant->status);
        $this->assertSame('grant_revoked', $session->refresh()->end_reason);
    }

    public function test_support_operator_without_mfa_and_grants_over_four_hours_are_rejected(): void
    {
        [, $studio] = $this->ownerFixture();
        $support = User::factory()->create(['two_factor_confirmed_at' => null]);
        PlatformSupportOperator::query()->create(['user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true]);
        $starts = CarbonImmutable::now()->startOfSecond();

        try {
            app(RequestSupportAccess::class)->handle($support, $studio, ['audit.read'], 'Investigate the customer support incident.', $starts, $starts->addHour(), 'no-mfa');
            $this->fail('A support operator without MFA requested access.');
        } catch (SupportAccessException $exception) {
            $this->assertSame('support_mfa_required', $exception->codeName);
        }

        $support->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->expectException(SupportAccessException::class);
        app(RequestSupportAccess::class)->handle($support, $studio, ['audit.read'], 'Investigate the customer support incident.', $starts, $starts->addHours(4)->addSecond(), 'too-long');
    }

    public function test_support_operator_cannot_approve_their_own_request_when_they_are_also_a_studio_administrator(): void
    {
        [, $studio] = $this->ownerFixture();
        $support = User::factory()->create(['two_factor_confirmed_at' => now()]);
        PlatformSupportOperator::query()->create([
            'user_id' => $support->getKey(),
            'capabilities' => ['studio_support'],
            'active' => true,
        ]);
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(),
            'user_id' => $support->getKey(),
            'role' => MembershipRole::Administrator,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'preferences' => [],
        ]);
        $starts = CarbonImmutable::now()->startOfSecond();
        $grant = app(RequestSupportAccess::class)->handle(
            $support,
            $studio,
            ['audit.read'],
            'Review an audit event reported by the studio.',
            $starts,
            $starts->addHour(),
            'self-approval-denied',
        );

        try {
            app(ApproveSupportAccess::class)->handle($support, $grant, 1);
            $this->fail('A dual-role support operator approved their own request.');
        } catch (SupportAccessException $exception) {
            $this->assertSame('support_independent_approval_required', $exception->codeName);
        }

        $this->assertSame(GrantStatus::Requested, $grant->refresh()->status);
        $this->assertNull($grant->approved_by_user_id);
    }

    public function test_support_session_can_be_ended_idempotently_by_its_operator(): void
    {
        [$owner, $studio] = $this->ownerFixture();
        $support = User::factory()->create(['two_factor_confirmed_at' => now()]);
        PlatformSupportOperator::query()->create(['user_id' => $support->getKey(), 'capabilities' => ['studio_support'], 'active' => true]);
        Queue::fake();
        $starts = CarbonImmutable::now()->startOfSecond();
        $grant = app(RequestSupportAccess::class)->handle($support, $studio, ['audit.read'],
            'Review the immutable audit trail for a support case.', $starts, $starts->addHour(), 'end-session');
        $grant = app(ApproveSupportAccess::class)->handle($owner, $grant, 1);
        $session = app(StartSupportSession::class)->handle($support, $grant, now()->toImmutable())['session'];

        $ended = app(EndSupportSession::class)->handle($support, $session);
        $replay = app(EndSupportSession::class)->handle($support, $ended);
        $this->assertSame($ended->ended_at->timestamp, $replay->ended_at->timestamp);
        $this->assertSame('operator_ended', $replay->end_reason);
        $this->assertFalse($replay->isActive());
    }

    /** @return array{User, Studio} */
    private function ownerFixture(): array
    {
        $owner = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $studio = Studio::factory()->create(['timezone' => 'UTC']);
        StudioMembership::query()->create([
            'studio_id' => $studio->getKey(), 'user_id' => $owner->getKey(),
            'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
            'joined_at' => now(), 'preferences' => [],
        ]);

        return [$owner, $studio];
    }

    private function assertMutationRejected(callable $operation): void
    {
        $ownsTransaction = DB::transactionLevel() === 0;
        if ($ownsTransaction) {
            DB::beginTransaction();
        }
        DB::statement('SAVEPOINT immutable_foundation');
        try {
            $operation();
            DB::statement('RELEASE SAVEPOINT immutable_foundation');
            if ($ownsTransaction) {
                DB::rollBack();
            }
            $this->fail('An immutable row was mutated.');
        } catch (QueryException $exception) {
            DB::statement('ROLLBACK TO SAVEPOINT immutable_foundation');
            DB::statement('RELEASE SAVEPOINT immutable_foundation');
            if ($ownsTransaction) {
                DB::rollBack();
            }
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
    }
}
