<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Audit\StudioDatabaseScope;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\Models\SupportAccessSession;
use Illuminate\Support\Facades\DB;

final class ExpireSupportAccess
{
    public function __construct(private readonly StudioDatabaseScope $scope, private readonly AuditWriter $audit) {}

    public function handle(int $limit = 500): int
    {
        $expired = 0;
        foreach (DB::table('studios')->orderBy('id')->pluck('id') as $studioId) {
            if ($expired >= $limit) {
                break;
            }
            $expired += $this->scope->run((string) $studioId, function () use ($studioId, $limit, $expired): int {
                $count = 0;
                $ids = SupportAccessGrant::query()->where('studio_id', $studioId)
                    ->whereIn('status', [GrantStatus::Requested, GrantStatus::Approved])
                    ->where('expires_at', '<=', now())->limit($limit - $expired)->pluck('id');
                foreach ($ids as $id) {
                    DB::transaction(function () use ($id, &$count): void {
                        $grant = SupportAccessGrant::query()->whereKey($id)->lockForUpdate()->first();
                        if ($grant === null || ! in_array($grant->status, [GrantStatus::Requested, GrantStatus::Approved], true)
                            || $grant->expires_at->gt(now())) {
                            return;
                        }
                        $grant->forceFill(['status' => GrantStatus::Expired, 'version' => $grant->version + 1])->save();
                        SupportAccessSession::query()->where('grant_id', $grant->getKey())->whereNull('ended_at')->update([
                            'ended_at' => now(), 'end_reason' => 'grant_expired', 'updated_at' => now(),
                        ]);
                        $this->audit->record(new AuditRecord(
                            studioId: $grant->studio_id, eventType: 'support_access.expired',
                            subjectType: 'support_access_grant', subjectId: (string) $grant->getKey(),
                            payload: SafeAuditPayload::from(['expired_at' => now()->toIso8601String()], ['expired_at']),
                            actorType: 'system', actorDisplay: 'Maestro system', correlationId: $grant->correlation_id,
                        ));
                        $count++;
                    }, 5);
                }

                return $count;
            });
        }

        return $expired;
    }
}
