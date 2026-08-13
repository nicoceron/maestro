<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'service_id', 'amount_minor', 'currency', 'effective_from', 'effective_until', 'active', 'version'])]
final class ServicePrice extends Model
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
            'amount_minor' => 'integer', 'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
