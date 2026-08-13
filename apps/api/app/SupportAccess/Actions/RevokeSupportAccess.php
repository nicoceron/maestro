<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Models\User;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use Illuminate\Support\Facades\DB;

final class RevokeSupportAccess
{
    public function __construct(private readonly SupportAccessPolicy $policy, private readonly AuditWriter $audit) {}

    public function handle(User $actor, SupportAccessGrant $grant, int $expectedVersion, string $reason): SupportAccessGrant
    {
        if (! $this->policy->manageStudio($actor, $grant->studio_id)) {
            throw new SupportAccessException('support_revocation_forbidden', 'Only a studio owner or administrator may revoke support access.', 403);
        }

        return DB::transaction(function () use ($actor, $grant, $expectedVersion, $reason): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion || $locked->status !== GrantStatus::Approved) {
                throw new SupportAccessException('support_grant_not_revocable', 'Only an approved support grant may be revoked.');
            }
            $locked->forceFill(['status' => GrantStatus::Revoked, 'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getAuthIdentifier(), 'decision_reason' => mb_substr(trim($reason), 0, 500),
                'version' => $locked->version + 1])->save();
            SupportAccessSession::query()->where('grant_id', $locked->getKey())->whereNull('ended_at')->update([
                'ended_at' => now(), 'end_reason' => 'grant_revoked', 'updated_at' => now(),
            ]);
            $this->audit->record(new AuditRecord(
                studioId: $locked->studio_id, eventType: 'support_access.revoked', subjectType: 'support_access_grant',
                subjectId: (string) $locked->getKey(), payload: SafeAuditPayload::from(['sessions_terminated' => true], ['sessions_terminated']),
                actor: $actor, correlationId: $locked->correlation_id,
            ));

            return $locked->refresh();
        }, 5);
    }
}
