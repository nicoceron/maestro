<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class StudioInvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canManage = $request->user() !== null;

        return [
            'id' => $this->getKey(),
            'email' => $this->email_normalized,
            'role' => $this->role->value,
            'status' => $this->status(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'send_count' => $this->send_count,
            'resend_available_at' => $this->resendAvailableAt()->toIso8601String(),
            'delivery_status' => $this->whenLoaded('latestDelivery', fn (): ?string => $this->latestDelivery?->status),
            'permissions' => [
                'can_resend' => $canManage
                    && Gate::forUser($request->user())->allows('resend', $this->resource)
                    && $this->canBeResent(),
                'can_revoke' => $canManage
                    && Gate::forUser($request->user())->allows('revoke', $this->resource)
                    && $this->status() === 'pending',
            ],
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
