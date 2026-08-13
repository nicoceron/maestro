<?php

namespace App\DataLifecycle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantDeletionRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'export_id' => $this->export_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'cooling_off_ends_at' => $this->cooling_off_ends_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'quarantined_at' => $this->quarantined_at?->toIso8601String(),
            'purge_eligible_at' => $this->purge_eligible_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'restored_at' => $this->restored_at?->toIso8601String(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
