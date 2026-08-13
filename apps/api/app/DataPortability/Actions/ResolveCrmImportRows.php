<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\Models\Household;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ResolveCrmImportRows
{
    /** @param list<array<string,mixed>> $resolutions */
    public function handle(Studio $studio, User $actor, CrmImportBatch $batch, int $expectedVersion, array $resolutions): CrmImportBatch
    {
        return DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion, $resolutions): CrmImportBatch {
            $locked = CrmImportBatch::query()->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())->lockForUpdate()->findOrFail($batch->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->version !== $expectedVersion) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_VERSION_CONFLICT');
            }
            if ($locked->schema_name === 'maestro.crm-portability') {
                throw new CrmDataPortabilityConflict('CRM_PORTABLE_DECISIONS_FORBIDDEN');
            }
            if ($locked->status !== 'needs_resolution') {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_STATE_INVALID');
            }

            foreach ($resolutions as $index => $resolution) {
                $row = CrmImportRow::query()->where('studio_id', $studio->getKey())
                    ->where('import_batch_id', $locked->getKey())->lockForUpdate()
                    ->findOrFail((string) ($resolution['row_id'] ?? ''));
                if ($row->status !== 'conflict' || $row->decision !== 'conflict') {
                    throw new CrmDataPortabilityConflict('CRM_IMPORT_ROW_STATE_INVALID');
                }
                if ((int) ($resolution['plan_version'] ?? 0) !== $row->plan_version) {
                    throw new CrmDataPortabilityConflict('CRM_IMPORT_PLAN_VERSION_CONFLICT');
                }
                $decision = (string) ($resolution['decision'] ?? '');
                if (! in_array($decision, ['create', 'update', 'skip'], true)) {
                    throw ValidationException::withMessages(["rows.{$index}.decision" => 'CRM_IMPORT_DECISION_INVALID']);
                }

                $personId = $resolution['candidate_person_id'] ?? null;
                $personVersion = isset($resolution['candidate_person_version']) ? (int) $resolution['candidate_person_version'] : null;
                $householdId = $resolution['candidate_household_id'] ?? null;
                $householdVersion = isset($resolution['candidate_household_version']) ? (int) $resolution['candidate_household_version'] : null;
                if ($decision === 'create' && $personId !== null) {
                    throw ValidationException::withMessages(["rows.{$index}.candidate_person_id" => 'CRM_IMPORT_CREATE_CANDIDATE_FORBIDDEN']);
                }
                if ($decision === 'update') {
                    $planned = collect($row->match_candidates['people'] ?? [])->first(fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === (string) $personId && (int) ($candidate['version'] ?? 0) === $personVersion);
                    if ($personId === null || $personVersion === null || $planned === null) {
                        throw ValidationException::withMessages(["rows.{$index}.candidate_person_id" => 'CRM_IMPORT_CANDIDATE_MISMATCH']);
                    }
                    $current = Person::query()->where('studio_id', $studio->getKey())->find($personId);
                    if ($current === null || $current->version !== $personVersion) {
                        throw new CrmDataPortabilityConflict('CRM_IMPORT_CANDIDATE_STALE');
                    }
                }
                if ($householdId !== null) {
                    $plannedHousehold = collect($row->match_candidates['households'] ?? [])->first(fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === (string) $householdId && (int) ($candidate['version'] ?? 0) === $householdVersion);
                    if ($plannedHousehold === null) {
                        throw ValidationException::withMessages(["rows.{$index}.candidate_household_id" => 'CRM_IMPORT_CANDIDATE_MISMATCH']);
                    }
                    $current = Household::query()->where('studio_id', $studio->getKey())->find($householdId);
                    if ($current === null || $current->version !== $householdVersion) {
                        throw new CrmDataPortabilityConflict('CRM_IMPORT_CANDIDATE_STALE');
                    }
                }

                $row->forceFill([
                    'status' => 'resolved', 'decision' => $decision,
                    'candidate_person_id' => $personId, 'candidate_person_version' => $personVersion,
                    'candidate_household_id' => $householdId, 'candidate_household_version' => $householdVersion,
                    'match_candidates' => null, 'resolved_by_id' => $actor->getAuthIdentifier(),
                    'plan_version' => $row->plan_version + 1,
                ])->save();
            }

            $remaining = $locked->rows()->where('status', 'conflict')->count();
            $locked->forceFill([
                'status' => $remaining === 0 ? 'ready' : 'needs_resolution',
                'conflicted_rows' => $remaining,
                'version' => $locked->version + 1,
            ])->save();

            return $locked->fresh();
        }, 3);
    }
}
