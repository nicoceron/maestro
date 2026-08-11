<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['studio_id', 'name', 'notes', 'version'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return HasMany<HouseholdMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /** @return HasMany<GuardianRelationship, $this> */
    public function guardianRelationships(): HasMany
    {
        return $this->hasMany(GuardianRelationship::class);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }
}
