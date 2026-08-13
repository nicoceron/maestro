<?php

namespace App\DataLifecycle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantRetentionPolicyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'export_ttl_hours' => $this->export_ttl_hours,
            'deletion_cooling_off_days' => $this->deletion_cooling_off_days,
            'deletion_quarantine_days' => $this->deletion_quarantine_days,
            'operational_retention_days' => $this->operational_retention_days,
            'media_retention_days' => $this->media_retention_days,
            'audit_retention_days' => $this->audit_retention_days,
            'legal_hold' => $this->legal_hold,
            'legal_hold_reason' => $this->legal_hold_reason,
            'legal_hold_placed_at' => $this->legal_hold_placed_at?->toIso8601String(),
            'legal_hold_released_at' => $this->legal_hold_released_at?->toIso8601String(),
            'version' => $this->version,
        ];
    }
}
