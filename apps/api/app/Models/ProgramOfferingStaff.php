<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['studio_id', 'program_offering_id', 'staff_profile_id', 'is_primary', 'active', 'version'])]
final class ProgramOfferingStaff extends Model
{
    use HasUlids, IsSchedulingRecord;

    protected $table = 'program_offering_staff';

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(ProgramOffering::class, 'program_offering_id');
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'active' => 'boolean', 'version' => 'integer'];
    }
}
