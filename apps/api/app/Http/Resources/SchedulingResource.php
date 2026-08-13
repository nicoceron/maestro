<?php

namespace App\Http\Resources;

use App\Models\Equipment;
use App\Models\Location;
use App\Models\StaffAccountLink;
use App\Models\StaffAvailabilityOverride;
use App\Support\Scheduling\SchedulingAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SchedulingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canManage = $request->user() !== null
            && app(SchedulingAccess::class)->canManage($request->user(), (string) $this->studio_id);
        $attributes = collect($this->resource->getAttributes())
            ->except(['studio_id', 'normalized_name'])
            ->all();

        if ($this->resource instanceof Location && ! $canManage) {
            $attributes['private_instructions'] = null;
            $attributes['online_url'] = null;
        }

        if ($this->resource instanceof Equipment && ! $canManage) {
            $attributes['notes'] = null;
        }

        if ($this->resource instanceof StaffAvailabilityOverride && ! $canManage) {
            unset($attributes['approval_status'], $attributes['enforcement']);
        }

        if ($this->resource instanceof StaffAccountLink && ! $canManage) {
            return [];
        }

        foreach ($this->resource->getCasts() as $key => $cast) {
            if (array_key_exists($key, $attributes)) {
                $value = $this->resource->getAttribute($key);
                $attributes[$key] = $value instanceof \BackedEnum ? $value->value : $value;
            }
        }

        return [
            ...$attributes,
            'resolved' => $this->resource->getAttribute('resolved'),
            'permissions' => [
                'edit' => $request->user()?->can('update', $this->resource) ?? false,
                'retire' => $request->user()?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
