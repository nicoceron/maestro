<?php

namespace App\Models;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'studio_id',
    'key',
    'name',
    'type',
    'applies_to',
    'options',
    'required',
    'active',
    'sort_order',
])]
class CustomFieldDefinition extends Model
{
    use HasUlids, SoftDeletes;

    protected static function booted(): void
    {
        self::saving(function (self $definition): void {
            $definition->key = Str::of((string) $definition->key)
                ->squish()
                ->lower()
                ->replaceMatches('/[^a-z0-9_-]+/', '-')
                ->trim('-_')
                ->toString();
            $definition->name = Str::squish((string) $definition->name);

            if ($definition->key === '' || $definition->name === '') {
                throw ValidationException::withMessages([
                    'key' => 'Custom field key and name are required.',
                ]);
            }
            $options = $definition->options;
            $usesOptions = in_array($definition->type, [
                CustomFieldType::Select,
                CustomFieldType::MultiSelect,
            ], true);

            if ($usesOptions) {
                $validOptions = is_array($options)
                    && array_is_list($options)
                    && $options !== []
                    && count($options) <= 100
                    && collect($options)->every(fn (mixed $option): bool => is_string($option)
                        && $option === Str::squish($option)
                        && $option !== ''
                        && mb_strlen($option) <= 100)
                    && count($options) === count(array_unique(array_map(
                        static fn (string $option): string => mb_strtolower($option),
                        $options,
                    )));

                if (! $validOptions) {
                    throw ValidationException::withMessages([
                        'options' => 'Select fields require a unique non-empty list of options.',
                    ]);
                }
            } elseif ($options !== null && $options !== []) {
                throw ValidationException::withMessages([
                    'options' => 'Only select fields may define options.',
                ]);
            } else {
                $definition->options = null;
            }

            if ($definition->exists
                && ($definition->isDirty('type')
                    || $definition->isDirty('applies_to')
                    || $definition->isDirty('options'))
                && $definition->values()->exists()) {
                throw ValidationException::withMessages([
                    'type' => 'Retire this field and create a replacement before changing its shape.',
                ]);
            }
        });
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'definition_id');
    }

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'applies_to' => CustomFieldAppliesTo::class,
            'options' => 'array',
            'required' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
