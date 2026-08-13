<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'staff_profile_id', 'from_location_id', 'to_location_id', 'minutes', 'active', 'version'])]
final class StaffTravelBuffer extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    protected function casts(): array
    {
        return ['minutes' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }
}
