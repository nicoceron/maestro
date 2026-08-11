<?php

namespace App\Models;

use App\Enums\GuardianRelationshipType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id',
    'household_id',
    'guardian_person_id',
    'student_person_id',
    'relationship',
    'is_legal_guardian',
    'is_emergency_contact',
    'is_authorized_pickup',
    'portal_permissions',
])]
class GuardianRelationship extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'guardian_person_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'student_person_id');
    }

    protected function casts(): array
    {
        return [
            'relationship' => GuardianRelationshipType::class,
            'is_legal_guardian' => 'boolean',
            'is_emergency_contact' => 'boolean',
            'is_authorized_pickup' => 'boolean',
            'portal_permissions' => 'array',
        ];
    }
}
