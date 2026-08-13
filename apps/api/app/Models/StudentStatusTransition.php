<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'studio_id',
    'student_profile_id',
    'person_id',
    'actor_id',
    'previous_status',
    'new_status',
    'reason',
    'occurred_at',
])]
class StudentStatusTransition extends Model
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Student status history is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Student status history is immutable.'));
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'previous_status' => StudentStatus::class,
            'new_status' => StudentStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
