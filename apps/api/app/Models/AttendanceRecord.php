<?php

namespace App\Models;

use App\Enums\AttendanceBillingDisposition;
use App\Enums\AttendanceMakeupDisposition;
use App\Enums\AttendanceOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'event_occurrence_id', 'event_occurrence_participant_id', 'person_id',
    'outcome', 'billing_disposition', 'makeup_disposition', 'minutes_late', 'reason',
    'recorded_by_user_id', 'recorded_at', 'version',
])]
final class AttendanceRecord extends Model
{
    use HasUlids;

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventOccurrenceParticipant::class, 'event_occurrence_participant_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    protected function casts(): array
    {
        return [
            'outcome' => AttendanceOutcome::class,
            'billing_disposition' => AttendanceBillingDisposition::class,
            'makeup_disposition' => AttendanceMakeupDisposition::class,
            'minutes_late' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
