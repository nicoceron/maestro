<?php

namespace App\Models;

use App\Enums\InstrumentRelationship;
use App\Enums\ProficiencyLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id',
    'person_id',
    'instrument_id',
    'relationship',
    'proficiency',
    'is_primary',
    'years_experience',
])]
class PersonInstrument extends Model
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

    /** @return BelongsTo<Instrument, $this> */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    protected function casts(): array
    {
        return [
            'relationship' => InstrumentRelationship::class,
            'proficiency' => ProficiencyLevel::class,
            'is_primary' => 'boolean',
            'years_experience' => 'integer',
        ];
    }
}
