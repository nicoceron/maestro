<?php

namespace App\Models;

use App\Enums\PersonStatus;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'studio_id',
    'user_id',
    'first_name',
    'last_name',
    'preferred_name',
    'email',
    'phone',
    'birth_date',
    'pronouns',
    'status',
    'version',
    'source',
    'external_reference',
    'preferred_locale',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public function displayName(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.($this->last_name ?? ''));
    }

    public function setEmailAttribute(?string $email): void
    {
        $normalized = $email === null ? '' : User::normalizeEmail($email);
        $this->attributes['email'] = $normalized === '' ? null : $normalized;
    }

    public function setFirstNameAttribute(string $name): void
    {
        $this->attributes['first_name'] = Str::squish($name);
    }

    public function setLastNameAttribute(?string $name): void
    {
        $value = $name === null ? '' : Str::squish($name);
        $this->attributes['last_name'] = $value === '' ? null : $value;
    }

    public function setPreferredNameAttribute(?string $name): void
    {
        $value = $name === null ? '' : Str::squish($name);
        $this->attributes['preferred_name'] = $value === '' ? null : $value;
    }

    public function setPhoneAttribute(?string $phone): void
    {
        $value = $phone === null ? '' : Str::squish($phone);
        $this->attributes['phone'] = $value === '' ? null : $value;
    }

    public function setSourceAttribute(?string $source): void
    {
        $value = $source === null ? '' : Str::squish($source);
        $this->attributes['source'] = $value === '' ? null : mb_strtolower($value);
    }

    public function setExternalReferenceAttribute(?string $reference): void
    {
        $value = $reference === null ? '' : Str::squish($reference);
        $this->attributes['external_reference'] = $value === '' ? null : $value;
    }

    public function setPreferredLocaleAttribute(?string $locale): void
    {
        $value = $locale === null ? '' : str_replace('_', '-', Str::squish($locale));
        $parts = explode('-', $value);

        if ($value !== '') {
            $parts[0] = mb_strtolower($parts[0]);

            if (isset($parts[1]) && strlen($parts[1]) === 2) {
                $parts[1] = mb_strtoupper($parts[1]);
            }
        }

        $this->attributes['preferred_locale'] = $value === '' ? null : implode('-', $parts);
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<HouseholdMember, $this> */
    public function householdMemberships(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    /** @return HasOne<StudentProfile, $this> */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** @return HasOne<StaffProfile, $this> */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /** @return HasMany<PersonInstrument, $this> */
    public function instrumentAssignments(): HasMany
    {
        return $this->hasMany(PersonInstrument::class);
    }

    /** @return HasMany<PersonTag, $this> */
    public function tagAssignments(): HasMany
    {
        return $this->hasMany(PersonTag::class);
    }

    /** @return HasMany<CustomFieldValue, $this> */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /** @return HasMany<StudentStatusTransition, $this> */
    public function studentStatusTransitions(): HasMany
    {
        return $this->hasMany(StudentStatusTransition::class);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'status' => PersonStatus::class,
            'version' => 'integer',
        ];
    }
}
