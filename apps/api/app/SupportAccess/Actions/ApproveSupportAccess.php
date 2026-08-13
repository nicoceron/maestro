<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Models\User;
use App\Outbox\OutboxRecord;
use App\Outbox\OutboxWriter;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use Illuminate\Support\Facades\DB;

final class ApproveSupportAccess
{
    public function __construct(
        private readonly SupportAccessPolicy $policy,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function handle(User $actor, SupportAccessGrant $grant, int $expectedVersion): SupportAccessGrant
    {
        if (! $this->policy->manageStudio($actor, $grant->studio_id)) {
            throw new SupportAccessException('support_approval_forbidden', 'Only a studio owner or administrator may approve support access.', 403);
        }
        if ($actor->two_factor_confirmed_at === null) {
            throw new SupportAccessException('support_mfa_required', 'Multi-factor authentication is required.', 423);
        }

        return DB::transaction(function () use ($actor, $grant, $expectedVersion): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion) {
                throw new SupportAccessException('support_version_conflict', 'The support grant changed in another session.');
            }
            if ((int) $locked->requested_by_user_id === (int) $actor->getAuthIdentifier()) {
                throw new SupportAccessException(
                    'support_independent_approval_required',
                    'The support operator who requested access may not approve their own grant.',
                    403,
                );
            }
            if ($locked->status !== GrantStatus::Requested || $locked->expires_at->lte(now())) {
                throw new SupportAccessException('support_grant_not_approvable', 'Only a current requested grant may be approved.');
            }
            $locked->forceFill([
                'status' => GrantStatus::Approved, 'approved_by_user_id' => $actor->getAuthIdentifier(),
                'approved_at' => now(), 'version' => $locked->version + 1,
            ])->save();
            $this->audit->record(new AuditRecord(
                studioId: $locked->studio_id, eventType: 'support_access.approved',
                subjectType: 'support_access_grant', subjectId: (string) $locked->getKey(),
                payload: SafeAuditPayload::from(['scopes' => $locked->scopes, 'expires_at' => $locked->expires_at->toIso8601String()], ['scopes', 'expires_at']),
                actor: $actor, correlationId: $locked->correlation_id,
            ));
            $this->outbox->record(new OutboxRecord(
                studioId: $locked->studio_id, topic: 'support_access.approved', aggregateType: 'support_access_grant',
                aggregateId: (string) $locked->getKey(), idempotencyKey: 'support-approved:'.$locked->version,
                payload: ['grant_id' => $locked->getKey(), 'requester_user_id' => $locked->requested_by_user_id,
                    'expires_at' => $locked->expires_at->toIso8601String()], correlationId: $locked->correlation_id,
            ));

            return $locked->refresh();
        }, 5);
    }
}
