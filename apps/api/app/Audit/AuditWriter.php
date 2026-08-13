<?php

namespace App\Audit;

use App\Audit\Models\TenantAuditEvent;
use App\Audit\Models\TenantAuditStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditWriter
{
    public function record(AuditRecord $record): TenantAuditEvent
    {
        return DB::transaction(function () use ($record): TenantAuditEvent {
            TenantAuditStream::query()->insertOrIgnore([
                'studio_id' => $record->studioId,
                'last_sequence' => 0,
                'last_hash' => null,
                'updated_at' => now(),
            ]);

            /** @var TenantAuditStream $stream */
            $stream = TenantAuditStream::query()
                ->whereKey($record->studioId)
                ->lockForUpdate()
                ->firstOrFail();

            $request = app()->bound('request') ? request() : null;
            $requestMetadata = $this->requestMetadata($request instanceof Request ? $request : null);
            $occurredAt = ($record->occurredAt ?? now()->toImmutable())->startOfSecond();
            $sequence = $stream->last_sequence + 1;
            $correlationId = $record->correlationId ?? $requestMetadata['correlation_id'];
            $payload = $record->payload->jsonSerialize();
            $actorId = $record->actor?->getAuthIdentifier();
            $hashInput = [
                'studio_id' => $record->studioId,
                'stream_sequence' => $sequence,
                'event_type' => $record->eventType,
                'subject_type' => $record->subjectType,
                'subject_id' => $record->subjectId,
                'actor_type' => $record->actorType,
                'actor_user_id' => $actorId,
                'support_session_id' => $record->supportSessionId,
                'request_id' => $requestMetadata['request_id'],
                'correlation_id' => $correlationId,
                'causation_id' => $record->causationId,
                'payload_version' => $record->payloadVersion,
                'payload' => $payload,
                'previous_hash' => $stream->last_hash,
                'occurred_at' => $occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ];
            $integrityHash = hash_hmac('sha256', CanonicalJson::encode($hashInput), $this->integrityKey());

            $event = TenantAuditEvent::query()->create([
                ...$hashInput,
                'actor_display' => $record->actorDisplay ?? $record->actor?->name,
                'request_method' => $requestMetadata['request_method'],
                'request_ip_hash' => $requestMetadata['request_ip_hash'],
                'user_agent_hash' => $requestMetadata['user_agent_hash'],
                'integrity_hash' => $integrityHash,
                'occurred_at' => $occurredAt,
            ]);

            $stream->forceFill([
                'last_sequence' => $sequence,
                'last_hash' => $integrityHash,
                'updated_at' => now(),
            ])->save();

            return $event;
        }, 5);
    }

    /** @return array{request_id: string, correlation_id: string, request_method: ?string, request_ip_hash: ?string, user_agent_hash: ?string} */
    private function requestMetadata(?Request $request): array
    {
        if ($request === null) {
            return [
                'request_id' => (string) Str::ulid(),
                'correlation_id' => (string) Str::ulid(),
                'request_method' => null,
                'request_ip_hash' => null,
                'user_agent_hash' => null,
            ];
        }

        $requestId = $request->attributes->get('_maestro_request_id');
        if (! is_string($requestId) || ! Str::isUlid($requestId)) {
            $requestId = (string) Str::ulid();
            $request->attributes->set('_maestro_request_id', $requestId);
        }
        $correlationId = $request->attributes->get('_maestro_correlation_id');
        if (! is_string($correlationId) || ! Str::isUlid($correlationId)) {
            $header = $request->header('X-Correlation-ID');
            $correlationId = is_string($header) && Str::isUlid($header) ? $header : (string) Str::ulid();
            $request->attributes->set('_maestro_correlation_id', $correlationId);
        }

        return [
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'request_method' => strtoupper($request->method()),
            'request_ip_hash' => $this->privacyHash($request->ip()),
            'user_agent_hash' => $this->privacyHash($request->userAgent()),
        ];
    }

    private function privacyHash(?string $value): ?string
    {
        return $value === null || $value === '' ? null : hash_hmac('sha256', $value, $this->integrityKey());
    }

    private function integrityKey(): string
    {
        $configured = (string) config('audit.integrity_key', config('app.key'));
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);

            return $decoded === false ? $configured : $decoded;
        }

        return $configured;
    }
}
