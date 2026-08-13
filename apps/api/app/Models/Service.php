<?php

namespace App\Models;

use App\Enums\MakeupPolicy;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'service_category_id', 'name', 'normalized_name', 'description',
    'default_duration_minutes', 'default_capacity', 'default_price_minor', 'currency',
    'booking_lead_minutes', 'cancellation_notice_minutes', 'makeup_policy', 'active', 'version',
])]
final class Service extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function policies(): HasMany
    {
        return $this->hasMany(ServicePolicy::class);
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(ProgramOffering::class);
    }

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer', 'default_capacity' => 'integer',
            'default_price_minor' => 'integer', 'booking_lead_minutes' => 'integer',
            'cancellation_notice_minutes' => 'integer', 'makeup_policy' => MakeupPolicy::class,
            'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
