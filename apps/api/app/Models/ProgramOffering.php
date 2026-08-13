<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'service_id', 'location_id', 'room_id', 'name', 'normalized_name',
    'description', 'timezone', 'duration_minutes', 'capacity', 'price_minor', 'currency',
    'starts_on', 'ends_on', 'enrollment_open', 'active', 'version',
])]
final class ProgramOffering extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(ProgramOfferingStaff::class);
    }

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer', 'capacity' => 'integer', 'price_minor' => 'integer',
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date',
            'enrollment_open' => 'boolean', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
