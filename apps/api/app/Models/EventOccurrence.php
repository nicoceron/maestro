<?php

namespace App\Models;

use App\Enums\EventKind;
use App\Enums\EventOccurrenceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'event_series_id', 'location_id', 'public_uid', 'recurrence_id_local',
    'starts_at', 'ends_at', 'utc_offset_minutes', 'timezone', 'source', 'status', 'title',
    'kind', 'capacity', 'price_minor', 'currency', 'policy_snapshot', 'source_snapshot',
    'version', 'makeup_required', 'makeup_reference', 'hold_expires_at', 'canceled_at', 'canceled_by_user_id', 'cancellation_reason',
])]
class EventOccurrence extends Model
{
    use HasUlids;

    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'event_series_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(EventOccurrenceTeacher::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(EventOccurrenceRoom::class);
    }

    public function equipmentReservations(): HasMany
    {
        return $this->hasMany(EventOccurrenceEquipment::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventOccurrenceParticipant::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function lessonNotes(): HasMany
    {
        return $this->hasMany(LessonNote::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'makeup_required' => 'boolean',
            'kind' => EventKind::class,
            'status' => EventOccurrenceStatus::class,
            'utc_offset_minutes' => 'integer',
            'capacity' => 'integer',
            'price_minor' => 'integer',
            'policy_snapshot' => 'array',
            'source_snapshot' => 'array',
            'version' => 'integer',
        ];
    }
}
