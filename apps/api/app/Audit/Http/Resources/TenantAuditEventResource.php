<?php

namespace App\Audit\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'event_type' => $this->event_type,
            'subject' => ['type' => $this->subject_type, 'id' => $this->subject_id],
            'actor' => ['type' => $this->actor_type, 'display' => $this->actor_display],
            'correlation_id' => $this->correlation_id,
            'payload_version' => $this->payload_version,
            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
