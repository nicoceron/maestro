<?php

namespace App\Support\Audit;

use App\Models\StudioAuditEvent;
use App\Models\StudioInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InvitationAudit
{
    /** @var list<string> */
    private const METADATA_KEYS = [
        'role',
        'delivery_version',
        'previous_invitation_id',
        'replacement_invitation_id',
        'reason',
    ];

    /** @param  array<string, mixed>  $metadata */
    public function record(
        StudioInvitation $invitation,
        string $eventType,
        ?User $actor = null,
        array $metadata = [],
        bool $includeRequestMetadata = true,
    ): StudioAuditEvent {
        [$requestId, $requestIpHash] = $includeRequestMetadata
            ? $this->requestMetadata()
            : [null, null];

        $safeMetadata = Arr::only($metadata, self::METADATA_KEYS);

        return StudioAuditEvent::query()->create([
            'studio_id' => $invitation->studio_id,
            'event_type' => $eventType,
            'subject_type' => 'studio_invitation',
            'subject_id' => $invitation->getKey(),
            'actor_id' => $actor?->getAuthIdentifier(),
            'request_id' => $requestId,
            'request_ip_hash' => $requestIpHash,
            'metadata' => $safeMetadata === [] ? (object) [] : $safeMetadata,
            'occurred_at' => now(),
        ]);
    }

    public function finalizeAcceptance(StudioInvitation $invitation, User $actor): void
    {
        [$requestId, $requestIpHash] = $this->requestMetadata();

        DB::statement(
            'select public.app_finalize_invitation_acceptance(?, ?, ?, ?, ?)',
            [
                $invitation->getKey(),
                (string) Str::ulid(),
                (string) Str::ulid(),
                $requestId ?? '',
                $requestIpHash ?? '',
            ],
        );
    }

    /** @return array{?string, ?string} */
    private function requestMetadata(): array
    {
        $request = app()->bound('request') ? request() : null;

        if (! $request instanceof Request) {
            return [null, null];
        }

        $requestId = $request->attributes->get('_maestro_audit_request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::ulid();
            $request->attributes->set('_maestro_audit_request_id', $requestId);
        }
        $ip = $request->ip();

        return [
            $requestId,
            $ip === null ? null : hash_hmac('sha256', $ip, (string) config('app.key')),
        ];
    }
}
