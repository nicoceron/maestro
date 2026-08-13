<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Audit\StudioDatabaseScope;
use App\Models\Studio;
use App\Models\User;
use App\Outbox\OutboxRecord;
use App\Outbox\OutboxWriter;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use App\SupportAccess\SupportScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestSupportAccess
{
    public function __construct(
        private readonly StudioDatabaseScope $studioScope,
        private readonly SupportAccessPolicy $policy,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param list<string> $scopes */
    public function handle(
        User $actor,
        Studio $studio,
        array $scopes,
        string $reason,
        CarbonImmutable $startsAt,
        CarbonImmutable $expiresAt,
        string $idempotencyKey,
    ): SupportAccessGrant {
        if (! $this->policy->isOperator($actor)) {
            throw new SupportAccessException('support_operator_required', 'An active support operator account is required.', 403);
        }
        if ($actor->two_factor_confirmed_at === null) {
            throw new SupportAccessException('support_mfa_required', 'Multi-factor authentication is required.', 423);
        }
        $scopes = array_values(array_unique($scopes));
        sort($scopes);
        if ($scopes === [] || array_diff($scopes, SupportScope::values()) !== []) {
            throw new SupportAccessException('support_scope_invalid', 'One or more support access scopes are invalid.', 422);
        }
        if ($startsAt->lt(now()->subMinute()) || $startsAt->gt(now()->addDays(7))
            || $expiresAt->lte($startsAt)
            || $expiresAt->gt($startsAt->addMinutes((int) config('support-access.grant_max_minutes', 240)))) {
            throw new SupportAccessException('support_window_invalid', 'Support grants must be scheduled within seven days and last no more than four hours.', 422);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 12 || mb_strlen($reason) > 500) {
            throw new SupportAccessException('support_reason_invalid', 'A specific support reason between 12 and 500 characters is required.', 422);
        }

        return $this->studioScope->run((string) $studio->getKey(), function () use ($actor, $studio, $scopes, $reason, $startsAt, $expiresAt, $idempotencyKey): SupportAccessGrant {
            return DB::transaction(function () use ($actor, $studio, $scopes, $reason, $startsAt, $expiresAt, $idempotencyKey): SupportAccessGrant {
                $existing = SupportAccessGrant::query()
                    ->where('requested_by_user_id', $actor->getAuthIdentifier())
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if ($existing->studio_id !== $studio->getKey() || $existing->scopes !== $scopes
                        || $existing->reason !== $reason || ! $existing->starts_at->equalTo($startsAt)
                        || ! $existing->expires_at->equalTo($expiresAt)) {
                        throw new SupportAccessException('support_idempotency_conflict', 'The idempotency key was already used with different request content.');
                    }

                    return $existing;
                }

                $correlationId = (string) Str::ulid();
                $grant = SupportAccessGrant::query()->create([
                    'studio_id' => $studio->getKey(), 'requested_by_user_id' => $actor->getAuthIdentifier(),
                    'idempotency_key' => $idempotencyKey, 'scopes' => $scopes, 'reason' => $reason,
                    'status' => GrantStatus::Requested, 'starts_at' => $startsAt, 'expires_at' => $expiresAt,
                    'version' => 1, 'correlation_id' => $correlationId,
                ]);
                $this->audit->record(new AuditRecord(
                    studioId: (string) $studio->getKey(), eventType: 'support_access.requested',
                    subjectType: 'support_access_grant', subjectId: (string) $grant->getKey(),
                    payload: SafeAuditPayload::from([
                        'scopes' => $scopes, 'starts_at' => $startsAt->toIso8601String(),
                        'expires_at' => $expiresAt->toIso8601String(), 'reason_length' => mb_strlen($reason),
                    ], ['scopes', 'starts_at', 'expires_at', 'reason_length']),
                    actor: $actor, actorType: 'support', correlationId: $correlationId,
                ));
                $this->outbox->record(new OutboxRecord(
                    studioId: (string) $studio->getKey(), topic: 'support_access.approval_requested',
                    aggregateType: 'support_access_grant', aggregateId: (string) $grant->getKey(),
                    idempotencyKey: 'support-request:'.$grant->getKey(),
                    payload: ['grant_id' => $grant->getKey(), 'studio_id' => $studio->getKey(), 'scopes' => $scopes,
                        'starts_at' => $startsAt->toIso8601String(), 'expires_at' => $expiresAt->toIso8601String()],
                    correlationId: $correlationId,
                ));

                return $grant;
            }, 5);
        });
    }
}
