<?php

namespace App\Models;

use App\Enums\AvailabilityEnforcement;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'staff_profile_id', 'weekday', 'start_time', 'end_time', 'timezone', 'enforcement', 'active', 'version'])]
final class StaffAvailabilityWindow extends Model
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

    protected function casts(): array
    {
        return [
            'weekday' => 'integer', 'enforcement' => AvailabilityEnforcement::class,
            'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
