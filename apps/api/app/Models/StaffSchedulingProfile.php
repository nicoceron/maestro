<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'staff_profile_id', 'timezone', 'default_buffer_before_minutes',
    'default_buffer_after_minutes', 'default_travel_buffer_minutes', 'max_daily_minutes',
    'max_weekly_minutes', 'active', 'version',
])]
final class StaffSchedulingProfile extends Model
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
            'default_buffer_before_minutes' => 'integer', 'default_buffer_after_minutes' => 'integer',
            'default_travel_buffer_minutes' => 'integer', 'max_daily_minutes' => 'integer',
            'max_weekly_minutes' => 'integer', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
