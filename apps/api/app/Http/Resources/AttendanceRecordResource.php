<?php

namespace App\Http\Resources;

use App\Enums\MembershipRole;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AttendanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = app(TenantContext::class)->membership()->role;
        $canManage = in_array($role, [MembershipRole::Owner, MembershipRole::Administrator, MembershipRole::Office], true);
        $billing = $role === MembershipRole::Billing;

        return [
            'id' => $this->id,
            'occurrence_id' => $this->event_occurrence_id,
            'participant_id' => $this->event_occurrence_participant_id,
            'person_id' => $billing ? null : $this->person_id,
            'outcome' => $this->outcome->value,
            'minutes_late' => $billing ? null : $this->minutes_late,
            'reason' => $billing ? null : $this->reason,
            'billing_disposition' => $this->when($canManage || $billing, $this->billing_disposition->value),
            'makeup_disposition' => $billing ? null : $this->makeup_disposition->value,
            'recorded_at' => $this->recorded_at->toAtomString(),
            'version' => $this->version,
            'corrections' => $this->when($canManage, fn () => $this->corrections->map(fn ($correction): array => [
                'id' => $correction->id,
                'previous_version' => $correction->previous_version,
                'reason' => $correction->reason,
                'occurred_at' => $correction->occurred_at->toAtomString(),
            ])),
        ];
    }
}
