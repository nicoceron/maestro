<?php

namespace App\Models;

use App\Enums\LocationKind;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'name', 'normalized_name', 'kind', 'timezone', 'address_line_1',
    'address_line_2', 'city', 'region', 'postal_code', 'country_code', 'online_url',
    'private_instructions', 'active', 'version',
])]
final class Location extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    protected function casts(): array
    {
        return ['kind' => LocationKind::class, 'active' => 'boolean', 'version' => 'integer'];
    }
}
