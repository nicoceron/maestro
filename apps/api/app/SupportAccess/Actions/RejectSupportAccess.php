<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Models\User;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use Illuminate\Support\Facades\DB;

final class RejectSupportAccess
{
    public function __construct(private readonly SupportAccessPolicy $policy, private readonly AuditWriter $audit) {}

    public function handle(User $actor, SupportAccessGrant $grant, int $expectedVersion, string $reason): SupportAccessGrant
    {
        if (! $this->policy->manageStudio($actor, $grant->studio_id)) {
            throw new SupportAccessException('support_rejection_forbidden', 'Only a studio owner or administrator may reject support access.', 403);
        }

        return DB::transaction(function () use ($actor, $grant, $expectedVersion, $reason): SupportAccessGrant {
            $locked = SupportAccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion || $locked->status !== GrantStatus::Requested) {
                throw new SupportAccessException('support_grant_not_rejectable', 'The support grant is no longer awaiting a decision.');
            }
            $locked->forceFill(['status' => GrantStatus::Rejected, 'rejected_at' => now(),
                'decision_reason' => mb_substr(trim($reason), 0, 500), 'version' => $locked->version + 1])->save();
            $this->audit->record(new AuditRecord(
                studioId: $locked->studio_id, eventType: 'support_access.rejected', subjectType: 'support_access_grant',
                subjectId: (string) $locked->getKey(), payload: SafeAuditPayload::from(['decision' => 'rejected'], ['decision']),
                actor: $actor, correlationId: $locked->correlation_id,
            ));

            return $locked->refresh();
        }, 5);
    }
}
