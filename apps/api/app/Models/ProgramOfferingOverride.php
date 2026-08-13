<?php

namespace App\Models;

use App\Enums\MakeupPolicy;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'program_offering_id', 'staff_profile_id', 'location_id', 'duration_minutes',
    'capacity', 'price_minor', 'currency', 'booking_lead_minutes', 'cancellation_notice_minutes',
    'makeup_policy', 'effective_from', 'effective_until', 'active', 'version',
])]
final class ProgramOfferingOverride extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(ProgramOffering::class, 'program_offering_id');
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer', 'capacity' => 'integer', 'price_minor' => 'integer',
            'booking_lead_minutes' => 'integer', 'cancellation_notice_minutes' => 'integer',
            'makeup_policy' => MakeupPolicy::class, 'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
