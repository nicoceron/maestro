<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['studio_id', 'location_id', 'room_id', 'name', 'normalized_name', 'quantity', 'notes', 'active', 'version'])]
final class Equipment extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomEquipment::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }
}
