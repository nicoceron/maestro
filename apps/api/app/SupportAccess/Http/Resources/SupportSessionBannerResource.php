<?php

namespace App\SupportAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SupportSessionBannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'banner' => 'Support access is active. Every view is scoped, visible, and audited.',
            'studio' => ['id' => $this->studio_id, 'name' => $this->whenLoaded('studio', fn () => $this->studio->name)],
            'scopes' => $this->scopes,
            'reason' => $this->reason,
            'approved_by' => ['display' => $this->whenLoaded('approver', fn () => $this->approver->name)],
            'started_at' => $this->started_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
        ];
    }
}
