<?php

namespace App\SupportAccess\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\Audit\StudioDatabaseScope;
use App\Models\User;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use App\SupportAccess\SupportSessionToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StartSupportSession
{
    public function __construct(
        private readonly StudioDatabaseScope $studioScope,
        private readonly SupportAccessPolicy $policy,
        private readonly SupportSessionToken $tokens,
        private readonly AuditWriter $audit,
    ) {}

    /** @return array{session: SupportAccessSession, access_token: string} */
    public function handle(User $actor, SupportAccessGrant $grant, CarbonImmutable $recentAuthAt): array
    {
        if (! $this->policy->viewOwnGrant($actor, $grant)) {
            throw new SupportAccessException('support_session_forbidden', 'This support grant does not belong to the current operator.', 403);
        }
        if ($actor->two_factor_confirmed_at === null) {
            throw new SupportAccessException('support_mfa_required', 'Multi-factor authentication is required.', 423);
        }
        if ($recentAuthAt->lt(now()->subSeconds((int) config('support-access.recent_auth_seconds', 600)))) {
            throw new SupportAccessException('support_recent_auth_required', 'Recent authentication is required.', 423);
        }

        return $this->studioScope->run($grant->studio_id, function () use ($actor, $grant, $recentAuthAt): array {
            return DB::transaction(function () use ($actor, $grant, $recentAuthAt): array {
                $locked = SupportAccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
                if (! $locked->isUsable()) {
                    throw new SupportAccessException('support_grant_inactive', 'The support grant is not currently active.');
                }
                if (SupportAccessSession::query()->where('grant_id', $locked->getKey())
                    ->whereNull('ended_at')->where('expires_at', '>', now())->exists()) {
                    throw new SupportAccessException('support_session_already_active', 'This grant already has an active support session.');
                }

                $token = $this->tokens->issue();
                $startedAt = now()->toImmutable();
                $expiresAt = $locked->expires_at->min($startedAt->addMinutes((int) config('support-access.session_max_minutes', 120)));
                $session = SupportAccessSession::query()->create([
                    'studio_id' => $locked->studio_id, 'grant_id' => $locked->getKey(),
                    'support_user_id' => $actor->getAuthIdentifier(), 'approved_by_user_id' => $locked->approved_by_user_id,
                    'scopes' => $locked->scopes, 'reason' => $locked->reason, 'token_hash' => $token['hash'],
                    'recent_auth_at' => $recentAuthAt, 'mfa_verified_at' => now(),
                    'started_at' => $startedAt, 'expires_at' => $expiresAt,
                ]);
                $this->audit->record(new AuditRecord(
                    studioId: $locked->studio_id, eventType: 'support_session.started',
                    subjectType: 'support_access_session', subjectId: (string) $session->getKey(),
                    payload: SafeAuditPayload::from(['scopes' => $session->scopes, 'expires_at' => $expiresAt->toIso8601String()], ['scopes', 'expires_at']),
                    actor: $actor, actorType: 'support', supportSessionId: (string) $session->getKey(),
                    correlationId: $locked->correlation_id,
                ));

                return ['session' => $session->refresh(), 'access_token' => $token['plain']];
            }, 5);
        });
    }
}
