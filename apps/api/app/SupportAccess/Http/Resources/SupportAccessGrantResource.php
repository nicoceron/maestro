<?php

namespace App\SupportAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportAccessGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'studio' => ['id' => $this->studio_id, 'name' => $this->whenLoaded('studio', fn () => $this->studio->name)],
            'requester' => ['display' => $this->whenLoaded('requester', fn () => $this->requester->name)],
            'approver' => ['display' => $this->whenLoaded('approver', fn () => $this->approver?->name)],
            'scopes' => $this->scopes,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
