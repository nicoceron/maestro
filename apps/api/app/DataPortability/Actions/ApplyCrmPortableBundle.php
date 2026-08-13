<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmPortableRefMap;
use App\DataPortability\Support\CrmPortableBundle;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Instrument;
use App\Models\PersonInstrument;
use App\Models\PersonTag;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final readonly class ApplyCrmPortableBundle
{
    public function __construct(private CrmPortableBundle $bundles) {}

    public function prepare(CrmImportBatch $batch): void
    {
        if ($batch->schema_name !== 'maestro.crm-portability') {
            return;
        }$data = $this->read($batch)['datasets'];
        DB::transaction(function () use ($batch, $data): void {
            foreach ($data['instruments'] as $row) {
                $model = Instrument::query()->firstOrCreate(['studio_id' => $batch->studio_id, 'normalized_name' => mb_strtolower(trim($row['name']))], ['name' => $row['name'], 'active' => $this->bool($row['active'])]);
                $this->map($batch, 'instrument', $row['instrument_ref'], $model->getKey());
            }
            foreach ($data['tags'] as $row) {
                $model = Tag::query()->firstOrCreate(['studio_id' => $batch->studio_id, 'normalized_name' => mb_strtolower(trim($row['name']))], ['name' => $row['name'], 'color' => $row['color'] ?: null, 'active' => $this->bool($row['active'])]);
                $this->map($batch, 'tag', $row['tag_ref'], $model->getKey());
            }
            foreach ($data['custom_field_definitions'] as $row) {
                $model = CustomFieldDefinition::query()->firstOrCreate(['studio_id' => $batch->studio_id, 'key' => $row['definition_key']], ['name' => $row['name'], 'type' => $row['type'], 'applies_to' => $row['applies_to'], 'options' => $row['options'] === '' ? null : json_decode($row['options'], true, flags: JSON_THROW_ON_ERROR), 'required' => $this->bool($row['required']), 'active' => $this->bool($row['active'])]);
                $this->map($batch, 'definition', $row['definition_key'], $model->getKey());
            }
            foreach ($data['households'] as $row) {
                $target = CrmPortableRefMap::query()->where('studio_id', $batch->studio_id)->where('import_batch_id', $batch->getKey())->where('source_type', 'household')->where('source_ref', $row['household_ref'])->value('target_id');
                $model = $target ? Household::query()->findOrFail($target) : Household::query()->create(['studio_id' => $batch->studio_id, 'name' => $row['name'], 'notes' => $row['notes'] ?: null]);
                $this->map($batch, 'household', $row['household_ref'], $model->getKey());
            }
        }, 3);
    }

    public function mapPerson(CrmImportBatch $batch, string $sourceRef, string $personId): void
    {
        if ($sourceRef !== '') {
            $this->map($batch, 'person', $sourceRef, $personId);
        }
    }

    public function finish(CrmImportBatch $batch, User $actor): void
    {
        if ($batch->schema_name !== 'maestro.crm-portability') {
            return;
        }$data = $this->read($batch)['datasets'];
        DB::transaction(function () use ($batch, $data, $actor): void {
            foreach ($data['student_profiles'] as $row) {
                $personId = $this->ref($batch, 'person', $row['person_ref']);
                $existing = StudentProfile::query()->where('studio_id', $batch->studio_id)->where('person_id', $personId)->first();
                if ($existing && $existing->status->value !== $row['status']) {
                    throw ValidationException::withMessages(['bundle' => 'CRM_BUNDLE_STUDENT_TRANSITION_REQUIRED']);
                }$profile = $existing ?: StudentProfile::query()->create(['studio_id' => $batch->studio_id, 'person_id' => $personId, 'status' => $row['status'], 'joined_on' => $row['joined_on'] ?: null, 'left_on' => $row['left_on'] ?: null, 'school_grade' => $row['school_grade'] ?: null, 'learning_preferences' => $row['learning_preferences'] === '' ? [] : json_decode($row['learning_preferences'], true, flags: JSON_THROW_ON_ERROR), 'lead_source' => $row['lead_source'] ?: null, 'trial_started_on' => $row['trial_started_on'] ?: null, 'waitlisted_on' => $row['waitlisted_on'] ?: null, 'status_changed_at' => now()]);
                if (! $existing) {
                    StudentStatusTransition::query()->create(['studio_id' => $batch->studio_id, 'student_profile_id' => $profile->getKey(), 'person_id' => $personId, 'actor_id' => $actor->getAuthIdentifier(), 'previous_status' => null, 'new_status' => $profile->status, 'reason' => 'Student profile restored from portable CRM bundle.', 'occurred_at' => now()]);
                }
            }
            foreach ($data['staff_profiles'] as $row) {
                StaffProfile::query()->updateOrCreate(['studio_id' => $batch->studio_id, 'person_id' => $this->ref($batch, 'person', $row['person_ref'])], ['roles' => json_decode($row['roles'], true, flags: JSON_THROW_ON_ERROR), 'status' => $row['status'], 'employment_type' => $row['employment_type'] ?: null, 'bio' => $row['bio'] ?: null, 'hire_on' => $row['hire_on'] ?: null, 'left_on' => $row['left_on'] ?: null, 'can_substitute' => $this->bool($row['can_substitute'])]);
            }
            foreach ($data['person_instruments'] as $row) {
                PersonInstrument::query()->updateOrCreate(['studio_id' => $batch->studio_id, 'person_id' => $this->ref($batch, 'person', $row['person_ref']), 'instrument_id' => $this->ref($batch, 'instrument', $row['instrument_ref'])], ['relationship' => $row['relationship'], 'proficiency' => $row['proficiency'] ?: null, 'is_primary' => $this->bool($row['is_primary']), 'years_experience' => $row['years_experience'] === '' ? null : (int) $row['years_experience']]);
            }
            foreach ($data['person_tags'] as $row) {
                PersonTag::query()->firstOrCreate(['studio_id' => $batch->studio_id, 'person_id' => $this->ref($batch, 'person', $row['person_ref']), 'tag_id' => $this->ref($batch, 'tag', $row['tag_ref'])]);
            }
            foreach ($data['custom_field_values'] as $row) {
                CustomFieldValue::query()->updateOrCreate(['studio_id' => $batch->studio_id, 'definition_id' => $this->ref($batch, 'definition', $row['definition_key']), 'person_id' => $this->ref($batch, 'person', $row['person_ref'])], ['value' => json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR)]);
            }
            foreach ($data['household_members'] as $row) {
                HouseholdMember::query()->updateOrCreate(['studio_id' => $batch->studio_id, 'household_id' => $this->ref($batch, 'household', $row['household_ref']), 'person_id' => $this->ref($batch, 'person', $row['person_ref'])], ['role' => $row['role'], 'is_primary_contact' => $this->bool($row['is_primary_contact']), 'receives_billing' => $this->bool($row['receives_billing'])]);
            }
            foreach ($data['guardian_relationships'] as $row) {
                GuardianRelationship::query()->firstOrCreate(['studio_id' => $batch->studio_id, 'household_id' => $this->ref($batch, 'household', $row['household_ref']), 'guardian_person_id' => $this->ref($batch, 'person', $row['guardian_person_ref']), 'student_person_id' => $this->ref($batch, 'person', $row['student_person_ref'])], ['relationship' => $row['relationship'], 'is_legal_guardian' => $this->bool($row['is_legal_guardian']), 'is_emergency_contact' => $this->bool($row['is_emergency_contact']), 'is_authorized_pickup' => $this->bool($row['is_authorized_pickup']), 'portal_permissions' => json_decode($row['portal_permissions'], true, flags: JSON_THROW_ON_ERROR)]);
            }
        }, 3);
    }

    private function read(CrmImportBatch $batch): array
    {
        $bytes = Storage::disk((string) config('data-portability.disk'))->get($batch->quarantine_path);
        if (! hash_equals($batch->source_sha256, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_SOURCE_INTEGRITY_FAILED']);
        }

return $this->bundles->parse($bytes);
    }

    private function map(CrmImportBatch $batch, string $type, string $source, string $target): void
    {
        CrmPortableRefMap::query()->updateOrCreate(['studio_id' => $batch->studio_id, 'import_batch_id' => $batch->getKey(), 'source_type' => $type, 'source_ref' => $source], ['target_id' => $target]);
    }

    private function ref(CrmImportBatch $batch, string $type, string $source): string
    {
        return (string) CrmPortableRefMap::query()->where('studio_id', $batch->studio_id)->where('import_batch_id', $batch->getKey())->where('source_type',$type)->where('source_ref',$source)->firstOrFail()->target_id;
    }

    private function bool(string $value): bool
    {
        if (! in_array($value,['true', 'false'],true)) {
            throw ValidationException::withMessages(['bundle' => 'CRM_BUNDLE_BOOLEAN_INVALID']);
        }

return $value === 'true';
    }
}
