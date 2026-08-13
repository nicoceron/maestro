<?php

namespace App\DataPortability\Models;

use App\Models\Household;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'import_batch_id', 'row_number', 'plan_version', 'content_hash', 'normalized_payload',
    'status', 'decision', 'match_kind', 'candidate_person_id', 'candidate_person_version',
    'candidate_household_id', 'candidate_household_version', 'match_candidates',
    'resolved_by_id', 'result_person_id',
    'result_household_id', 'result_digest', 'error_code', 'error_fields', 'attempts',
    'lease_token', 'leased_at', 'lease_expires_at', 'processed_at', 'purged_at',
])]
final class CrmImportRow extends Model
{
    use HasUlids;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CrmImportBatch::class, 'import_batch_id');
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function candidatePerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'candidate_person_id');
    }

    public function candidateHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'candidate_household_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    protected function casts(): array
    {
        return [
            'normalized_payload' => 'array',
            'match_candidates' => 'array',
            'error_fields' => 'array',
            'candidate_person_version' => 'integer',
            'candidate_household_version' => 'integer',
            'plan_version' => 'integer',
            'leased_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
