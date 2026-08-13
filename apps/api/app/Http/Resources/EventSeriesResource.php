<?php

namespace App\Http\Resources;

use App\Models\Studio;
use App\Support\Scheduling\CalendarAccessContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EventSeriesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['teachers', 'rooms', 'equipmentRequirements']);
        $context = $request->attributes->get(CalendarAccessContext::class);

        if (! $context instanceof CalendarAccessContext || $context->studioId !== $this->studio_id) {
            $context = CalendarAccessContext::for(Studio::query()->findOrFail($this->studio_id), $request->user());
            $request->attributes->set(CalendarAccessContext::class, $context);
        }

        $canManage = $context->canManage();
        $canSeeOperationalAssignments = $canManage || $context->isAssignedToSeries($this->resource);

        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'visibility' => $this->visibility,
            'service_id' => $this->service_id, 'program_offering_id' => $this->program_offering_id,
            'location_id' => $context->isBilling() ? null : $this->location_id,
            'title' => $this->title, 'shared_description' => $this->shared_description,
            'internal_description' => $canManage ? $this->internal_description : null,
            'timezone' => $this->timezone, 'dtstart_local' => $this->dtstart_local,
            'dtstart_resolution' => $this->dtstart_resolution,
            'duration_minutes' => $this->duration_minutes, 'rrule' => $this->rrule,
            'rdates' => $this->rdates ?? [], 'exdates' => $this->exdates ?? [], 'capacity' => $this->capacity,
            'hold_expires_at' => $this->hold_expires_at?->toAtomString(),
            'version' => $this->version,
            'teachers' => $canSeeOperationalAssignments ? $this->teachers->map(fn ($teacher): array => [
                'staff_profile_id' => $teacher->staff_profile_id, 'role' => $teacher->role,
            ])->values() : [],
            'room_ids' => $canSeeOperationalAssignments ? $this->rooms->pluck('room_id')->values() : [],
            'equipment' => $canSeeOperationalAssignments ? $this->equipmentRequirements->map(fn ($equipment): array => [
                'equipment_id' => $equipment->equipment_id, 'quantity' => $equipment->quantity,
            ])->values() : [],
            'capabilities' => [
                'can_edit' => $canManage,
                'can_cancel' => $canManage,
                'can_clone' => $canManage,
                'can_manage_roster' => $canManage,
                'can_convert_hold' => $canManage && $this->hold_expires_at !== null,
                'can_release_hold' => $canManage && $this->hold_expires_at !== null,
            ],
            'occurrences' => EventOccurrenceResource::collection($this->whenLoaded('occurrences')),
        ];
    }
}
