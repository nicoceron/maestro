<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id',
    'person_id',
    'status',
    'joined_on',
    'left_on',
    'school_grade',
    'learning_preferences',
    'lead_source',
    'trial_started_on',
    'waitlisted_on',
    'status_changed_at',
])]
class StudentProfile extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return HasMany<StudentStatusTransition, $this> */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(StudentStatusTransition::class);
    }

    protected function casts(): array
    {
        return [
            'status' => StudentStatus::class,
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'learning_preferences' => 'array',
            'trial_started_on' => 'immutable_date',
            'waitlisted_on' => 'immutable_date',
            'status_changed_at' => 'immutable_datetime',
        ];
    }
}
