<?php

namespace App\Models;

use App\Enums\EventKind;
use App\Enums\EventSeriesStatus;
use App\Enums\EventVisibility;
use App\Enums\LocalTimeResolution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'service_id', 'program_offering_id', 'location_id', 'pricing_staff_profile_id', 'parent_series_id', 'cloned_from_series_id',
    'split_from_recurrence_id_local', 'kind', 'status', 'visibility', 'title', 'shared_description', 'internal_description',
    'timezone', 'dtstart_local', 'dtstart_resolution', 'duration_minutes', 'rrule', 'rdates',
    'exdates', 'recurrence_ends_before_local', 'capacity', 'hold_expires_at', 'materialized_through', 'version',
])]
class EventSeries extends Model
{
    use HasUlids;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(EventSeriesTeacher::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(EventSeriesRoom::class);
    }

    public function equipmentRequirements(): HasMany
    {
        return $this->hasMany(EventSeriesEquipment::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(EventEnrollment::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => EventKind::class,
            'status' => EventSeriesStatus::class,
            'visibility' => EventVisibility::class,
            'dtstart_resolution' => LocalTimeResolution::class,
            'rdates' => 'array',
            'exdates' => 'array',
            'materialized_through' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'capacity' => 'integer',
            'version' => 'integer',
        ];
    }
}
