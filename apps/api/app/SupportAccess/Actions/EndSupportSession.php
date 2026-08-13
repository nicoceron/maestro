<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Audit\StudioDatabaseScope;
use App\Models\User;
use App\SupportAccess\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessException;
use Illuminate\Support\Facades\DB;

final class EndSupportSession
{
    public function __construct(
        private readonly StudioDatabaseScope $studioScope,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(User $actor, SupportAccessSession $session, string $reason = 'operator_ended'): SupportAccessSession
    {
        if ($session->support_user_id !== $actor->getAuthIdentifier()) {
            throw new SupportAccessException('support_session_forbidden', 'The session does not belong to the current operator.', 403);
        }

        return $this->studioScope->run($session->studio_id, function () use ($actor, $session, $reason): SupportAccessSession {
            return DB::transaction(function () use ($actor, $session, $reason): SupportAccessSession {
                $locked = SupportAccessSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->ended_at !== null) {
                    return $locked;
                }
                $locked->forceFill(['ended_at' => now(), 'end_reason' => mb_substr($reason, 0, 80)])->save();
                $this->audit->record(new AuditRecord(
                    studioId: $locked->studio_id, eventType: 'support_session.ended',
                    subjectType: 'support_access_session', subjectId: (string) $locked->getKey(),
                    payload: SafeAuditPayload::from(['end_reason' => $locked->end_reason], ['end_reason']),
                    actor: $actor, actorType: 'support', supportSessionId: (string) $locked->getKey(),
                    correlationId: $locked->grant->correlation_id,
                ));

                return $locked->refresh();
            }, 5);
        });
    }
}
