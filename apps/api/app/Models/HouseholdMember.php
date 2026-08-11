<?php

namespace App\Models;

use App\Enums\HouseholdMemberRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id',
    'household_id',
    'person_id',
    'role',
    'is_primary_contact',
    'receives_billing',
])]
class HouseholdMember extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    protected function casts(): array
    {
        return [
            'role' => HouseholdMemberRole::class,
            'is_primary_contact' => 'boolean',
            'receives_billing' => 'boolean',
        ];
    }
}
