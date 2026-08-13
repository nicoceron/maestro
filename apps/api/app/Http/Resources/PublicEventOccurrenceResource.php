<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicEventOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('series');

        return [
            'uid' => $this->public_uid,
            'starts_at' => $this->starts_at->toAtomString(),
            'ends_at' => $this->ends_at->toAtomString(),
            'timezone' => $this->timezone,
            'utc_offset_minutes' => $this->utc_offset_minutes,
            'title' => $this->title,
            'kind' => $this->kind,
            'shared_description' => $this->series->shared_description,
        ];
    }
}
