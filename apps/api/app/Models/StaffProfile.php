<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'studio_id',
    'person_id',
    'roles',
    'status',
    'employment_type',
    'bio',
    'hire_on',
    'left_on',
    'can_substitute',
])]
class StaffProfile extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        self::saving(function (self $profile): void {
            $roles = array_values(array_map(
                static fn (mixed $role): string => $role instanceof \BackedEnum
                    ? (string) $role->value
                    : (string) $role,
                Arr::wrap($profile->roles),
            ));
            $allowed = array_column(StaffRole::cases(), 'value');

            if ($roles === []
                || count($roles) > 3
                || count($roles) !== count(array_unique($roles))
                || array_diff($roles, $allowed) !== []) {
                throw ValidationException::withMessages([
                    'staff.roles' => 'Staff roles must be a unique list of supported roles.',
                ]);
            }

            $profile->roles = $roles;

            if ($profile->status === StaffStatus::Former) {
                $profile->left_on ??= now()->toDateString();
            } else {
                $profile->left_on = null;
            }

            if ($profile->hire_on !== null
                && $profile->left_on !== null
                && $profile->left_on->isBefore($profile->hire_on)) {
                throw ValidationException::withMessages([
                    'staff.left_on' => 'The staff departure date cannot precede the hire date.',
                ]);
            }
        });
    }

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

    public function accountLink(): HasOne
    {
        return $this->hasOne(StaffAccountLink::class);
    }

    public function schedulingProfile(): HasOne
    {
        return $this->hasOne(StaffSchedulingProfile::class);
    }

    public function availabilityWindows(): HasMany
    {
        return $this->hasMany(StaffAvailabilityWindow::class);
    }

    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany(StaffAvailabilityOverride::class);
    }

    public function travelBuffers(): HasMany
    {
        return $this->hasMany(StaffTravelBuffer::class);
    }

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'status' => StaffStatus::class,
            'employment_type' => EmploymentType::class,
            'hire_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'can_substitute' => 'boolean',
        ];
    }
}
