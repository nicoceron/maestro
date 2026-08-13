<?php

namespace App\Models;

use App\Models\Concerns\IsSchedulingRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['studio_id', 'name', 'normalized_name', 'description', 'color', 'sort_order', 'active', 'version'])]
final class ServiceCategory extends Model
{
    use HasUlids, IsSchedulingRecord;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }
}
