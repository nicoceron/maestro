<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pivot = $this->resource->getRelationValue('pivot');

        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'currency' => $this->currency,
            'week_starts_on' => $this->week_starts_on,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'membership' => $this->when($pivot !== null, fn (): array => [
                'role' => $pivot->role instanceof \BackedEnum ? $pivot->role->value : $pivot->role,
                'status' => $pivot->status instanceof \BackedEnum ? $pivot->status->value : $pivot->status,
            ]),
            'permissions' => [
                'manage' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
