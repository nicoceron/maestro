<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'staff_profile_id', 'studio_membership_id', 'active', 'version'])]
final class StaffAccountLink extends Model
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

    public function membership(): BelongsTo
    {
        return $this->belongsTo(StudioMembership::class, 'studio_membership_id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
