<?php

namespace App\DataLifecycle\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantRestoreDrillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'export_id' => $this->export_id,
            'status' => $this->status->value,
            'dry_run' => $this->dry_run,
            'target_studio_id' => $this->target_studio_id,
            'tenant_id_remap' => $this->tenant_id_remap,
            'verification' => $this->verification,
            'error_code' => $this->error_code,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
