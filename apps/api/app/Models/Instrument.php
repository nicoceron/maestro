<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable(['studio_id', 'name', 'normalized_name', 'active'])]
class Instrument extends Model
{
    use HasUlids, SoftDeletes;

    protected static function booted(): void
    {
        self::saving(function (self $instrument): void {
            $name = Str::squish((string) $instrument->name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Instrument name is required.']);
            }

            $instrument->name = $name;
            $instrument->normalized_name = mb_strtolower($name);
        });
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return HasMany<PersonInstrument, $this> */
    public function personAssignments(): HasMany
    {
        return $this->hasMany(PersonInstrument::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
