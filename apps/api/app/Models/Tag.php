<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable(['studio_id', 'name', 'normalized_name', 'color', 'active'])]
class Tag extends Model
{
    use HasUlids, SoftDeletes;

    protected static function booted(): void
    {
        self::saving(function (self $tag): void {
            $name = Str::squish((string) $tag->name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Tag name is required.']);
            }

            $tag->name = $name;
            $tag->normalized_name = mb_strtolower($name);
            $tag->color = filled($tag->color) ? mb_strtoupper((string) $tag->color) : null;

            if ($tag->color !== null && preg_match('/^#[0-9A-F]{6}$/', $tag->color) !== 1) {
                throw ValidationException::withMessages(['color' => 'Tag color must be a six-digit hex color.']);
            }
        });
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return HasMany<PersonTag, $this> */
    public function personAssignments(): HasMany
    {
        return $this->hasMany(PersonTag::class);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
