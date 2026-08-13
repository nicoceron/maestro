<?php

namespace App\Models;

use App\Enums\MakeupPolicy;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'service_id', 'booking_lead_minutes', 'cancellation_notice_minutes', 'makeup_policy', 'effective_from', 'effective_until', 'active', 'version'])]
final class ServicePolicy extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected function casts(): array
    {
        return [
            'booking_lead_minutes' => 'integer', 'cancellation_notice_minutes' => 'integer',
            'makeup_policy' => MakeupPolicy::class, 'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
