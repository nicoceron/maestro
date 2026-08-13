<?php

namespace App\Audit;

use App\Audit\Models\TenantAuditEvent;

final class AuditIntegrityVerifier
{
    /** @return array{valid: bool, checked: int, failed_event_id: ?string} */
    public function verify(string $studioId): array
    {
        $previousHash = null;
        $checked = 0;

        foreach (TenantAuditEvent::query()->where('studio_id', $studioId)->orderBy('stream_sequence')->cursor() as $event) {
            $checked++;
            $input = [
                'studio_id' => $event->studio_id,
                'stream_sequence' => $event->stream_sequence,
                'event_type' => $event->event_type,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'actor_type' => $event->actor_type,
                'actor_user_id' => $event->actor_user_id,
                'support_session_id' => $event->support_session_id,
                'request_id' => $event->request_id,
                'correlation_id' => $event->correlation_id,
                'causation_id' => $event->causation_id,
                'payload_version' => $event->payload_version,
                'payload' => $event->payload,
                'previous_hash' => $event->previous_hash,
                'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            ];
            $expected = hash_hmac('sha256', CanonicalJson::encode($input), $this->integrityKey());
            if ($event->previous_hash !== $previousHash || ! hash_equals($expected, $event->integrity_hash)) {
                return ['valid' => false, 'checked' => $checked, 'failed_event_id' => (string) $event->getKey()];
            }
            $previousHash = $event->integrity_hash;
        }

        return ['valid' => true, 'checked' => $checked, 'failed_event_id' => null];
    }

    private function integrityKey(): string
    {
        $configured = (string) config('audit.integrity_key', config('app.key'));
        if (str_starts_with($configured, 'base64:')) {
            return base64_decode(substr($configured, 7), true) ?: $configured;
        }

        return $configured;
    }
}
