<?php

namespace App\Models;

use App\Enums\StudioStatus;
use Database\Factories\StudioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'status',
    'timezone',
    'locale',
    'currency',
    'week_starts_on',
    'settings',
    'trial_ends_at',
])]
class Studio extends Model
{
    /** @use HasFactory<StudioFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'studio_memberships')
            ->using(StudioMembership::class)
            ->withPivot(['id', 'role', 'status', 'job_title', 'joined_at', 'last_active_at', 'preferences'])
            ->withTimestamps();
    }

    /** @return HasMany<Household, $this> */
    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    /** @return HasMany<StudioInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(StudioInvitation::class);
    }

    /** @return HasMany<Person, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'status' => StudioStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'immutable_datetime',
        ];
    }
}
