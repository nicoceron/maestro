<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityEnforcement;
use App\Enums\AvailabilityOverrideType;
use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'staff_profile_id', 'kind', 'approval_status', 'enforcement', 'starts_at', 'ends_at', 'timezone', 'reason', 'active', 'version'])]
final class StaffAvailabilityOverride extends Model
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
            'kind' => AvailabilityOverrideType::class, 'approval_status' => ApprovalStatus::class,
            'enforcement' => AvailabilityEnforcement::class, 'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime', 'active' => 'boolean', 'version' => 'integer',
        ];
    }
}
