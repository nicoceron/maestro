<?php

namespace App\Models;

use App\Enums\PersonStatus;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'studio_id',
    'user_id',
    'first_name',
    'last_name',
    'preferred_name',
    'email',
    'phone',
    'birth_date',
    'pronouns',
    'status',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public function displayName(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.($this->last_name ?? ''));
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<HouseholdMember, $this> */
    public function householdMemberships(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'status' => PersonStatus::class,
        ];
    }
}
