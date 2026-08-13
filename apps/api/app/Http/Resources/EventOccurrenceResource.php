<?php

namespace App\Http\Resources;

use App\Models\Studio;
use App\Support\Scheduling\CalendarAccessContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EventOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['series', 'location', 'teachers', 'rooms', 'equipmentReservations', 'participants']);
        $context = $request->attributes->get(CalendarAccessContext::class);

        if (! $context instanceof CalendarAccessContext || $context->studioId !== $this->studio_id) {
            $context = CalendarAccessContext::for(
                Studio::query()->findOrFail($this->studio_id),
                $request->user(),
            );
        }
        $canManage = $context->canManage();
        $operational = $context->canViewOperationalDetails($this->resource);
        $onlineUrl = $context->canViewOnlineJoinUrl($this->resource) ? $this->location?->online_url : null;

        return [
            'id' => $this->id, 'series_id' => $this->event_series_id, 'public_uid' => $this->public_uid,
            'recurrence_id_local' => $this->recurrence_id_local, 'starts_at' => $this->starts_at->toAtomString(),
            'ends_at' => $this->ends_at->toAtomString(), 'timezone' => $this->timezone,
            'utc_offset_minutes' => $this->utc_offset_minutes, 'status' => $this->status,
            'kind' => $this->kind, 'title' => $this->title, 'capacity' => $this->capacity,
            'price_minor' => $this->when($canManage, $this->price_minor),
            'currency' => $this->when($canManage, $this->currency),
            'policy' => $this->when($canManage, $this->policy_snapshot),
            'location_id' => $this->when(! $context->isBilling(), $this->location_id),
            'online_join_url' => $onlineUrl,
            'teachers' => $operational ? $this->teachers->map(fn ($teacher): array => [
                'staff_profile_id' => $teacher->staff_profile_id, 'role' => $teacher->role,
            ])->values() : [],
            'room_ids' => $operational ? $this->rooms->pluck('room_id')->values() : [],
            'equipment' => $operational ? $this->equipmentReservations->map(fn ($equipment): array => [
                'equipment_id' => $equipment->equipment_id, 'quantity' => $equipment->quantity,
            ])->values() : [],
            'participants' => $operational ? $this->participants->map(fn ($participant): array => [
                'person_id' => $participant->person_id, 'role' => $participant->role, 'status' => $participant->status,
            ])->values() : [],
            'is_hold' => $this->hold_expires_at !== null, 'hold_expires_at' => $this->hold_expires_at?->toAtomString(),
            'makeup_required' => $this->when($canManage, $this->makeup_required),
            'makeup_reference' => $this->when($canManage, $this->makeup_reference),
            'version' => $this->version,
            'capabilities' => ['can_reschedule' => $canManage, 'can_cancel' => $canManage, 'can_restore' => $canManage],
        ];
    }
}
