<?php

namespace App\DataPortability\Actions;

use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\CanonicalJson;
use App\Audit\SafeAuditPayload;
use App\DataPortability\Jobs\BuildCrmPortableExportJob;
use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\DataPortability\Support\CrmPortableCsv;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Support\TenantArchiveCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class BuildCrmPortableExport
{
    public function __construct(private CrmPortableCsv $csv, private AuditWriter $audit, private TenantArchiveCipher $cipher) {}

    public function request(Studio $studio, User $actor, string $idempotencyKey): CrmPortableExport
    {
        Gate::forUser($actor)->authorize('create', [CrmPortableExport::class, $studio]);
        $fingerprint = hash('sha256', implode('|', [$studio->getKey(), $actor->getAuthIdentifier(), 'crm-export-v1']));

        return DB::transaction(function () use ($studio, $actor, $idempotencyKey, $fingerprint): CrmPortableExport {
            DB::table('studios')->where('id', $studio->getKey())->lockForUpdate()->firstOrFail();
            $existing = CrmPortableExport::query()->where('studio_id', $studio->getKey())->where('requested_by_id', $actor->getAuthIdentifier())
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new CrmDataPortabilityConflict('IDEMPOTENCY_KEY_REUSED');
                }

                return $existing;
            }

            $export = CrmPortableExport::query()->create([
                'studio_id' => $studio->getKey(), 'requested_by_id' => $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint,
                'status' => 'queued', 'expires_at' => now()->addHours((int) config('data-portability.artifact_ttl_hours', 48)),
            ]);
            DB::afterCommit(fn () => BuildCrmPortableExportJob::dispatch($studio->getKey(), $export->getKey()));

            return $export->refresh();
        }, 3);
    }

    public function build(Studio $studio, User $actor, CrmPortableExport $export): CrmPortableExport
    {
        Gate::forUser($actor)->authorize('view', $export);
        try {
            $export->forceFill(['status' => 'building', 'version' => $export->version + 1])->save();
            [$specs,$snapshotBoundary] = DB::transaction(function () use ($studio): array {
                if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return [$this->datasetSpecs($studio), $this->snapshotBoundary($studio)];
            });
            $datasets = [];
            $manifestDatasets = [];
            foreach ($specs as $name => $spec) {
                $columns = $spec['columns'];
                $rows = $spec['rows'];
                $bytes = $this->csv->encodeDataset($columns, $rows);
                $path = 'datasets/'.$name.'.csv';
                $datasets[$path] = base64_encode($bytes);
                $dependencies = ['instruments' => [], 'tags' => [], 'custom_field_definitions' => [], 'households' => [], 'people' => [], 'student_profiles' => ['people'], 'staff_profiles' => ['people'], 'person_instruments' => ['people', 'instruments'], 'person_tags' => ['people', 'tags'], 'custom_field_values' => ['people', 'custom_field_definitions'], 'household_members' => ['households', 'people'], 'guardian_relationships' => ['households', 'people']][$name];
                $manifestDatasets[] = ['name' => $name, 'schema_version' => '1.0', 'path' => $path, 'media_type' => 'text/csv; charset=utf-8; header=present', 'columns' => $columns, 'dependencies' => $dependencies, 'row_count' => count($rows), 'byte_count' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
            $manifest = [
                'schema' => 'maestro.crm-portability', 'version' => '1.0',
                'studio' => ['timezone' => $studio->timezone, 'locale' => $studio->locale, 'currency' => $studio->currency],
                'canonicalization' => ['csv' => 'RFC4180; CRLF; every field double-quoted; final CRLF', 'cell_encoding' => 'base64-v1 for formula-effective or marker-prefixed literals'],
                'snapshot_boundary' => $snapshotBoundary,
                'application' => ['name' => 'maestro', 'schema_version' => 'crm-1.0'],
                'datasets' => $manifestDatasets, 'excluded' => ['passwords', 'tokens', 'secrets', 'global_user_ids', 'global_actor_ids', 'internal_storage_paths', 'internal_row_ids', 'internal_versions'],
            ];
            $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest));
            $bundle = CanonicalJson::encode(['manifest' => $manifest, 'datasets' => $datasets]);
            $ciphertext = $this->cipher->encrypt($bundle);
            $path = 'exports/'.$studio->getKey().'/'.$export->getKey().'.maestro';
            Storage::disk((string) config('data-portability.disk'))->put($path, $ciphertext);

            return DB::transaction(function () use ($studio, $actor, $export, $manifest, $path, $ciphertext, $bundle, $manifestDatasets): CrmPortableExport {
                $locked = CrmPortableExport::query()->where('studio_id', $studio->getKey())->where('requested_by_id', $actor->getAuthIdentifier())->lockForUpdate()->findOrFail($export->getKey());
                if ($locked->expires_at->isPast() || $locked->purged_at !== null) {
                    Storage::disk((string) config('data-portability.disk'))->delete($path);
                    throw new CrmDataPortabilityConflict('CRM_EXPORT_EXPIRED');
                }
                $locked->forceFill(['status' => 'ready', 'manifest' => $manifest, 'archive_path' => $path, 'archive_sha256' => hash('sha256', $ciphertext), 'archive_size' => strlen($bundle), 'ready_at' => now(), 'version' => $locked->version + 1])->save();
                $this->audit->record(new AuditRecord($studio->getKey(), 'crm.export.ready', 'crm_portable_export', $locked->getKey(), SafeAuditPayload::from(['dataset_count' => count($manifestDatasets), 'row_count' => collect($manifestDatasets)->sum('row_count')], ['dataset_count', 'row_count']), $actor));

                return $locked->fresh();
            });
        } catch (Throwable $exception) {
            $export->forceFill(['status' => 'failed', 'error_code' => 'CRM_EXPORT_BUILD_FAILED', 'failure_digest' => hash('sha256', get_class($exception))])->save();
            throw $exception;
        }
    }

    public function decryptedArchive(CrmPortableExport $export): string
    {
        $ciphertext = Storage::disk((string) config('data-portability.disk'))->get((string) $export->archive_path);
        if (! hash_equals((string) $export->archive_sha256, hash('sha256', $ciphertext))) {
            throw ValidationException::withMessages(['archive' => 'CRM_EXPORT_CHECKSUM_MISMATCH']);
        }

        return $this->cipher->decrypt($ciphertext);
    }

    private function datasetSpecs(Studio $studio): array
    {
        $idMap = fn (string $table, string $prefix): array => DB::table($table)->where('studio_id', $studio->getKey())->orderBy('id')->pluck('id')->values()->mapWithKeys(fn ($id, $index) => [(string) $id => $prefix.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT)])->all();
        $personRefs = $idMap('people', 'person-');
        $householdRefs = $idMap('households', 'household-');
        $instrumentRefs = $idMap('instruments', 'instrument-');
        $tagRefs = $idMap('tags', 'tag-');
        $definitionKeys = DB::table('custom_field_definitions')->where('studio_id', $studio->getKey())->pluck('key', 'id')->all();
        $rows = function (string $table, array $columns, callable $map) use ($studio): array {
            return DB::table($table)->where('studio_id', $studio->getKey())->orderBy('id')->get()->map(fn ($row) => array_map(fn ($column) => $this->scalar($map($row, $column), $column), $columns))->all();
        };

        return [
            'instruments' => ['columns' => $c = ['instrument_ref', 'name', 'active'], 'rows' => $rows('instruments', $c, fn ($r, $c) => $c === 'instrument_ref' ? $instrumentRefs[$r->id] : $r->{$c})],
            'tags' => ['columns' => $c = ['tag_ref', 'name', 'color', 'active'], 'rows' => $rows('tags', $c, fn ($r, $c) => $c === 'tag_ref' ? $tagRefs[$r->id] : $r->{$c})],
            'custom_field_definitions' => ['columns' => $c = ['definition_key', 'name', 'type', 'applies_to', 'options', 'required', 'active'], 'rows' => $rows('custom_field_definitions', $c, fn ($r, $c) => $c === 'definition_key' ? $r->key : $r->{$c})],
            'households' => ['columns' => $c = ['household_ref', 'name', 'notes'], 'rows' => $rows('households', $c, fn ($r, $c) => $c === 'household_ref' ? $householdRefs[$r->id] : $r->{$c})],
            'people' => ['columns' => $c = ['person_ref', 'first_name', 'last_name', 'preferred_name', 'email', 'phone', 'birth_date', 'pronouns', 'status', 'external_reference', 'preferred_locale'], 'rows' => $rows('people', $c, fn ($r, $c) => $c === 'person_ref' ? $personRefs[$r->id] : $r->{$c})],
            'student_profiles' => ['columns' => $c = ['person_ref', 'status', 'joined_on', 'left_on', 'school_grade', 'learning_preferences', 'lead_source', 'trial_started_on', 'waitlisted_on'], 'rows' => $rows('student_profiles', $c, fn ($r, $c) => $c === 'person_ref' ? ($personRefs[$r->person_id] ?? '') : $r->{$c})],
            'staff_profiles' => ['columns' => $c = ['person_ref', 'roles', 'status', 'employment_type', 'bio', 'hire_on', 'left_on', 'can_substitute'], 'rows' => $rows('staff_profiles', $c, fn ($r, $c) => $c === 'person_ref' ? ($personRefs[$r->person_id] ?? '') : $r->{$c})],
            'person_instruments' => ['columns' => $c = ['person_ref', 'instrument_ref', 'relationship', 'proficiency', 'is_primary', 'years_experience'], 'rows' => $rows('person_instruments', $c, fn ($r, $c) => match ($c) {
                'person_ref' => $personRefs[$r->person_id] ?? '','instrument_ref' => $instrumentRefs[$r->instrument_id] ?? '',default => $r->{$c}
            })],
            'person_tags' => ['columns' => $c = ['person_ref', 'tag_ref'], 'rows' => $rows('person_tags', $c, fn ($r, $c) => $c === 'person_ref' ? ($personRefs[$r->person_id] ?? '') : ($tagRefs[$r->tag_id] ?? ''))],
            'custom_field_values' => ['columns' => $c = ['definition_key', 'person_ref', 'value'], 'rows' => $rows('custom_field_values', $c, fn ($r, $c) => match ($c) {
                'definition_key' => $definitionKeys[$r->definition_id] ?? '','person_ref' => $personRefs[$r->person_id] ?? '',default => $r->{$c}
            })],
            'household_members' => ['columns' => $c = ['household_ref', 'person_ref', 'role', 'is_primary_contact', 'receives_billing'], 'rows' => $rows('household_members', $c, fn ($r, $c) => match ($c) {
                'household_ref' => $householdRefs[$r->household_id] ?? '','person_ref' => $personRefs[$r->person_id] ?? '',default => $r->{$c}
            })],
            'guardian_relationships' => ['columns' => $c = ['household_ref', 'guardian_person_ref', 'student_person_ref', 'relationship', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup', 'portal_permissions'], 'rows' => $rows('guardian_relationships', $c, fn ($r, $c) => match ($c) {
                'household_ref' => $householdRefs[$r->household_id] ?? '','guardian_person_ref' => $personRefs[$r->guardian_person_id] ?? '','student_person_ref' => $personRefs[$r->student_person_id] ?? '',default => $r->{$c}
            })],
        ];
    }

    private function scalar(mixed $value, string $column): string
    {
        if (in_array($column, ['active', 'required', 'can_substitute', 'is_primary', 'is_primary_contact', 'receives_billing', 'is_legal_guardian', 'is_emergency_contact', 'is_authorized_pickup'], true) && in_array($value, [0, 1, '0', '1'], true)) {
            return (int) $value === 1 ? 'true' : 'false';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return CanonicalJson::encode((array) $value);
        }

        return (string) ($value ?? '');
    }

    private function snapshotBoundary(Studio $studio): string
    {
        $latest = '1970-01-01T00:00:00+00:00';
        foreach (['instruments', 'tags', 'custom_field_definitions', 'households', 'people', 'student_profiles', 'staff_profiles', 'person_instruments', 'person_tags', 'custom_field_values', 'household_members', 'guardian_relationships'] as $table) {
            $value = DB::table($table)->where('studio_id',$studio->getKey())->max('updated_at');
            if ($value !== null && $value > $latest) {
                $latest = (string) $value;
            }
        }

        return $latest;
    }
}
