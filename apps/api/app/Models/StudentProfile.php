<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id',
    'person_id',
    'status',
    'joined_on',
    'left_on',
    'school_grade',
    'learning_preferences',
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

    protected function casts(): array
    {
        return [
            'status' => StudentStatus::class,
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'learning_preferences' => 'array',
        ];
    }
}
