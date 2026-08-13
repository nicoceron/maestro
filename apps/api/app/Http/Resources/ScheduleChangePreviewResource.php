<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ScheduleChangePreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conflicts = collect($this->conflicts)->map(fn (array $conflict): array => [
            'severity' => $conflict['severity'], 'code' => $conflict['code'], 'message' => $conflict['message'],
        ])->values();

        return [
            'id' => $this->id, 'command_type' => $this->command_type, 'scope' => $this->scope,
            'status' => $this->status, 'impact' => $this->impact, 'conflicts' => $conflicts,
            'soft_warnings_acknowledged' => $this->soft_warnings_acknowledged,
            'expires_at' => $this->expires_at?->toAtomString(),
            'capabilities' => [
                'can_commit' => $this->status->value === 'ready' && $this->consumed_at === null && $this->expires_at->isFuture(),
                'can_acknowledge_soft_warnings' => collect($this->conflicts)->contains('severity', 'soft'),
                'hard_conflicts_overrideable' => false,
            ],
        ];
    }
}
