<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\DataPortability\Support\CrmPortableBundle;
use App\DataPortability\Support\CrmPortableCsv;
use App\Models\Household;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final readonly class PreviewCrmImport
{
    public function __construct(private CrmPortableCsv $csv, private CrmPortableBundle $bundle) {}

    /** @param array<string,string> $mapping @return array{batch:CrmImportBatch,rows:list<array<string,mixed>>} */
    public function handle(Studio $studio, User $actor, CrmImportBatch $batch, int $expectedVersion, array $mapping = []): array
    {
        $snapshot = DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion): array {
            $locked = CrmImportBatch::query()
                ->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())
                ->lockForUpdate()->findOrFail($batch->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            $this->assertVersionAndState($locked, $expectedVersion);

            return $locked->only(['quarantine_path', 'source_sha256', 'source_size', 'version', 'schema_name']);
        });

        $bytes = Storage::disk((string) config('data-portability.disk'))->get($snapshot['quarantine_path']);
        if (strlen($bytes) !== (int) $snapshot['source_size'] || ! hash_equals($snapshot['source_sha256'], hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_SOURCE_INTEGRITY_FAILED']);
        }
        if ($snapshot['schema_name'] === 'maestro.crm-portability') {
            foreach (['people', 'households', 'instruments', 'tags', 'custom_field_definitions'] as $table) {
                if (DB::table($table)->where('studio_id', $studio->getKey())->exists()) {
                    throw new CrmDataPortabilityConflict('CRM_PORTABLE_TARGET_NOT_EMPTY');
                }
            }
            $bundle = $this->bundle->parse($bytes);
            $parsed = ['portable' => true, 'columns' => CrmPortableCsv::PEOPLE_COLUMNS, 'rows' => collect($bundle['datasets']['people'])->values()->map(fn (array $values, int $offset) => [
                'record_number' => $offset + 2,
                'values' => array_replace(array_fill_keys(CrmPortableCsv::PEOPLE_COLUMNS, ''), [
                    'external_reference' => $values['external_reference'] ?? '', 'first_name' => $values['first_name'] ?? '', 'last_name' => $values['last_name'] ?? '',
                    'preferred_name' => $values['preferred_name'] ?? '', 'email' => $values['email'] ?? '', 'phone' => $values['phone'] ?? '',
                    'birth_date' => $values['birth_date'] ?? '', 'pronouns' => $values['pronouns'] ?? '', 'status' => $values['status'] ?? 'active',
                    'preferred_locale' => $values['preferred_locale'] ?? '', '_portable_person_ref' => $values['person_ref'] ?? '',
                ]),
            ])->all()];
        } else {
            $parsed = $this->csv->parse($bytes);
        }
        $effectiveMapping = $this->validateMapping($parsed['columns'], $mapping, $parsed['portable']);
        $people = $this->personIndexes($studio);
        $households = $this->householdIndexes($studio);

        return DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion, $effectiveMapping, $parsed, $people, $households, $snapshot): array {
            $locked = CrmImportBatch::query()
                ->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())
                ->lockForUpdate()->findOrFail($batch->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            $this->assertVersionAndState($locked, $expectedVersion);
            if ($locked->quarantine_path !== $snapshot['quarantine_path'] || ! hash_equals($locked->source_sha256, $snapshot['source_sha256'])) {
                throw ValidationException::withMessages(['file' => 'CRM_IMPORT_SOURCE_CHANGED']);
            }

            $locked->rows()->whereNotIn('status', ['created', 'updated', 'skipped', 'failed'])->delete();
            $seen = [];
            $rowCount = 0;
            $conflicts = 0;
            $invalid = 0;
            foreach ($parsed['rows'] as $record) {
                try {
                    $payload = $this->mapRow($record['values'], $effectiveMapping);
                } catch (ValidationException) {
                    CrmImportRow::query()->create(['studio_id' => $studio->getKey(), 'import_batch_id' => $locked->getKey(), 'row_number' => $record['record_number'], 'plan_version' => 1, 'content_hash' => hash('sha256', json_encode($record['values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'normalized_payload' => null, 'status' => 'failed', 'decision' => 'skip', 'match_kind' => 'invalid', 'error_code' => 'CRM_IMPORT_FIRST_NAME_REQUIRED', 'error_fields' => ['first_name'], 'result_digest' => hash('sha256', 'CRM_IMPORT_FIRST_NAME_REQUIRED'), 'processed_at' => now()]);
                    $rowCount++;
                    $invalid++;

                    continue;
                }
                if (isset($record['values']['_portable_person_ref'])) {
                    $payload['_portable_person_ref'] = $record['values']['_portable_person_ref'];
                }
                $match = $this->match($payload, $people, $households);
                $identity = $this->identity($payload);
                if (isset($seen[$identity])) {
                    $match = ['kind' => 'file_duplicate', 'people' => [], 'household' => null, 'households' => []];
                }
                $seen[$identity] = true;
                $needsResolution = $match['kind'] !== 'none';
                $row = CrmImportRow::query()->create([
                    'studio_id' => $studio->getKey(), 'import_batch_id' => $locked->getKey(),
                    'row_number' => $record['record_number'],
                    'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                    'normalized_payload' => $payload,
                    'status' => $needsResolution ? 'conflict' : 'resolved',
                    'decision' => $needsResolution ? 'conflict' : 'create',
                    'match_kind' => $match['kind'],
                    'candidate_person_id' => count($match['people']) === 1 ? $match['people'][0]['id'] : null,
                    'candidate_person_version' => count($match['people']) === 1 ? $match['people'][0]['version'] : null,
                    'candidate_household_id' => $match['household']['id'] ?? null,
                    'candidate_household_version' => $match['household']['version'] ?? null,
                    'match_candidates' => ['people' => array_slice($match['people'], 0, 5), 'households' => array_slice($match['households'], 0, 5)],
                ]);
                $rowCount++;
                if ($needsResolution) {
                    $conflicts++;
                }
            }
            $locked->forceFill([
                'status' => $conflicts === 0 ? 'ready' : 'needs_resolution',
                'column_mapping' => $effectiveMapping, 'row_count' => $rowCount,
                'conflicted_rows' => $conflicts, 'failed_rows' => $invalid, 'processed_rows' => $invalid, 'previewed_at' => now(),
                'version' => $locked->version + 1,
            ])->save();

            return ['batch' => $locked->fresh(), 'rows' => []];
        }, 3);
    }

    private function assertVersionAndState(CrmImportBatch $batch, int $expectedVersion): void
    {
        if ($batch->version !== $expectedVersion) {
            throw new CrmDataPortabilityConflict('CRM_IMPORT_VERSION_CONFLICT');
        }
        if (! in_array($batch->status, ['staged', 'needs_resolution', 'ready'], true)) {
            throw new CrmDataPortabilityConflict('CRM_IMPORT_STATE_INVALID');
        }
    }

    /** @param list<string> $columns @param array<string,string> $mapping @return array<string,string> */
    private function validateMapping(array $columns, array $mapping, bool $portable): array
    {
        if ($portable || ($columns === CrmPortableCsv::PEOPLE_COLUMNS && $mapping === [])) {
            return array_combine(CrmPortableCsv::PEOPLE_COLUMNS, CrmPortableCsv::PEOPLE_COLUMNS);
        }
        $effective = [];
        foreach ($mapping as $source => $canonical) {
            if (! in_array($canonical, CrmPortableCsv::PEOPLE_COLUMNS, true) || ! in_array($source, $columns, true)) {
                throw ValidationException::withMessages(['mapping' => 'CRM_IMPORT_MAPPING_INVALID']);
            }
            $effective[$canonical] = $source;
        }
        foreach (CrmPortableCsv::PEOPLE_COLUMNS as $canonical) {
            if (! isset($effective[$canonical]) && in_array($canonical, $columns, true)) {
                $effective[$canonical] = $canonical;
            }
        }
        if (! isset($effective['first_name']) || count(array_unique($effective)) !== count($effective)) {
            throw ValidationException::withMessages(['mapping' => 'CRM_IMPORT_MAPPING_INVALID']);
        }

        return $effective;
    }

    /** @param array<string,string> $values @param array<string,string> $mapping @return array<string,string> */
    private function mapRow(array $values, array $mapping): array
    {
        $payload = [];
        foreach (CrmPortableCsv::PEOPLE_COLUMNS as $column) {
            $payload[$column] = isset($mapping[$column]) ? ($values[$mapping[$column]] ?? '') : '';
        }
        if (trim($payload['first_name']) === '') {
            throw ValidationException::withMessages(['mapping' => 'CRM_IMPORT_FIRST_NAME_REQUIRED']);
        }

        return $payload;
    }

    /** @return array<string,array<string,list<array{id:string,version:int,display_name:string}>>> */
    private function personIndexes(Studio $studio): array
    {
        $indexes = ['id' => [], 'external' => [], 'email' => [], 'name_birth' => []];
        Person::query()->where('studio_id', $studio->getKey())->orderBy('id')
            ->get(['id', 'version', 'first_name', 'last_name', 'email', 'birth_date', 'external_reference'])
            ->each(function (Person $person) use (&$indexes): void {
                $item = ['id' => $person->getKey(), 'version' => $person->version, 'display_name' => $person->displayName()];
                $indexes['id'][$person->getKey()][] = $item;
                if ($person->external_reference) {
                    $indexes['external'][$person->external_reference][] = $item;
                }
                if ($person->email) {
                    $indexes['email'][$this->csv->normalizeKey($person->email)][] = $item;
                }
                $key = $this->csv->normalizeKey($person->first_name).'|'.$this->csv->normalizeKey((string) $person->last_name).'|'.($person->birth_date?->toDateString() ?? '');
                $indexes['name_birth'][$key][] = $item;
            });

        return $indexes;
    }

    /** @return array<string,array<string,list<array{id:string,version:int,name:string}>>> */
    private function householdIndexes(Studio $studio): array
    {
        $indexes = ['id' => [], 'name' => []];
        Household::query()->where('studio_id', $studio->getKey())->orderBy('id')->get(['id', 'version', 'name'])
            ->each(function (Household $household) use (&$indexes): void {
                $item = ['id' => $household->getKey(), 'version' => $household->version, 'name' => $household->name];
                $indexes['id'][$household->getKey()][] = $item;
                $indexes['name'][$this->csv->normalizeKey($household->name)][] = $item;
            });

        return $indexes;
    }

    /** @param array<string,string> $payload @param array<string,mixed> $people @param array<string,mixed> $households */
    private function match(array $payload, array $people, array $households): array
    {
        if ($payload['person_id'] !== '') {
            $kind = 'portable_id';
            $matches = $people['id'][$payload['person_id']] ?? [];
        } elseif ($payload['external_reference'] !== '') {
            $kind = 'external_reference';
            $matches = $people['external'][$payload['external_reference']] ?? [];
        } elseif ($payload['email'] !== '') {
            $kind = 'email';
            $matches = $people['email'][$this->csv->normalizeKey($payload['email'])] ?? [];
        } else {
            $kind = 'name_birth_date';
            $matches = $people['name_birth'][$this->csv->normalizeKey($payload['first_name']).'|'.$this->csv->normalizeKey($payload['last_name']).'|'.$payload['birth_date']] ?? [];
        }
        $household = null;
        $householdMatches = $payload['household_id'] !== ''
            ? ($households['id'][$payload['household_id']] ?? [])
            : ($payload['household_name'] !== '' ? ($households['name'][$this->csv->normalizeKey($payload['household_name'])] ?? []) : []);
        if (count($householdMatches) === 1) {
            $household = $householdMatches[0];
        } elseif (count($householdMatches) > 1) {
            $kind = 'ambiguous_household';
        }

        $finalKind = count($matches) > 1 ? 'ambiguous_'.$kind : (count($matches) === 0 ? ($household !== null ? 'household' : 'none') : $kind);

        return ['kind' => $finalKind, 'people' => $matches, 'household' => $household, 'households' => $householdMatches];
    }

    /** @param array<string,string> $payload */
    private function identity(array $payload): string
    {
        return hash('sha256', implode('|', [$payload['person_id'], $payload['external_reference'], $this->csv->normalizeKey($payload['email']), $this->csv->normalizeKey($payload['first_name']), $this->csv->normalizeKey($payload['last_name']), $payload['birth_date']]));
    }
}
