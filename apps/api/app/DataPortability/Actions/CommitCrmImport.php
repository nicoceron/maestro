<?php

namespace App\DataPortability\Actions;

use App\Actions\People\CreatePerson;
use App\Actions\People\UpdatePerson;
use App\Audit\AuditRecord;
use App\Audit\AuditWriter;
use App\Audit\SafeAuditPayload;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\Models\Household;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use App\Outbox\OutboxRecord;
use App\Outbox\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class CommitCrmImport
{
    public function __construct(
        private CreatePerson $createPerson,
        private UpdatePerson $updatePerson,
        private ApplyImportedHouseholdMembership $households,
        private ApplyCrmPortableBundle $portable,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    public function handle(Studio $studio, User $actor, CrmImportBatch $batch, int $expectedVersion, ?string $commandId = null): CrmImportBatch
    {
        if ($batch->schema_name === 'maestro.crm-portability') {
            return $this->commitPortable($studio, $actor, $batch, $expectedVersion, $commandId);
        }
        $batch = DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion, $commandId): CrmImportBatch {
            $locked = CrmImportBatch::query()->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())->lockForUpdate()->findOrFail($batch->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            if ($commandId !== null && (string) $locked->active_command_id !== $commandId) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_RUN_CONFLICT');
            }
            if ($locked->version !== $expectedVersion && ! ($locked->status === 'committing' && $locked->version >= $expectedVersion + 1)) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_VERSION_CONFLICT');
            }
            if (in_array($locked->status, ['completed', 'completed_with_errors'], true)) {
                return $locked;
            }
            if ($locked->expires_at->isPast() || $locked->purged_at !== null) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_EXPIRED');
            }
            if (! in_array($locked->status, ['ready', 'committing'], true) || $locked->rows()->where('status', 'conflict')->exists()) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_NOT_RESOLVED');
            }
            if ($locked->schema_name === 'maestro.crm-portability' && $locked->status === 'ready') {
                DB::table('studios')->where('id', $studio->getKey())->lockForUpdate()->firstOrFail();
                foreach (['people', 'households', 'instruments', 'tags', 'custom_field_definitions'] as $table) {
                    if (DB::table($table)->where('studio_id', $studio->getKey())->exists()) {
                        throw new CrmDataPortabilityConflict('CRM_PORTABLE_TARGET_NOT_EMPTY');
                    }
                }
                $this->portable->prepare($locked);
            }
            if ($locked->status === 'ready') {
                $locked->forceFill(['status' => 'committing', 'commit_started_at' => now(), 'version' => $locked->version + 1])->save();
            }

            return $locked;
        }, 3);

        if ($batch->schema_name !== 'maestro.crm-portability') {
            $this->portable->prepare($batch);
        }

        $processedThisRun = 0;
        while ($processedThisRun < 100 && ($row = $this->claim($studio, $batch))) {
            try {
                $this->apply($studio, $actor, $batch, $row);
            } catch (Throwable $exception) {
                $this->fail($studio, $batch, $row, $exception);
            }
            $processedThisRun++;
        }

        return DB::transaction(function () use ($studio, $actor, $batch): CrmImportBatch {
            $locked = CrmImportBatch::query()->where('studio_id', $studio->getKey())->lockForUpdate()->findOrFail($batch->getKey());
            $counts = CrmImportRow::query()->where('studio_id', $studio->getKey())->where('import_batch_id', $locked->getKey())
                ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
            $processed = collect(['created', 'updated', 'skipped', 'failed'])->sum(fn (string $key): int => (int) ($counts[$key] ?? 0));
            $nonTerminal = collect(['pending', 'resolved', 'processing', 'conflict'])->sum(fn (string $key): int => (int) ($counts[$key] ?? 0));
            if ($nonTerminal > 0) {
                $locked->forceFill(['processed_rows' => $processed, 'created_rows' => (int) ($counts['created'] ?? 0), 'updated_rows' => (int) ($counts['updated'] ?? 0), 'skipped_rows' => (int) ($counts['skipped'] ?? 0), 'failed_rows' => (int) ($counts['failed'] ?? 0)])->save();

                return $locked->fresh();
            }
            $this->portable->finish($locked, $actor);
            $locked->forceFill([
                'status' => ((int) ($counts['failed'] ?? 0)) > 0 ? 'completed_with_errors' : 'completed',
                'processed_rows' => $processed, 'created_rows' => (int) ($counts['created'] ?? 0),
                'updated_rows' => (int) ($counts['updated'] ?? 0), 'skipped_rows' => (int) ($counts['skipped'] ?? 0),
                'failed_rows' => (int) ($counts['failed'] ?? 0), 'completed_at' => now(), 'active_command_id' => null, 'version' => $locked->version + 1,
            ])->save();
            $this->audit->record(new AuditRecord($studio->getKey(), 'crm.import.completed', 'crm_import_batch', $locked->getKey(),
                SafeAuditPayload::from(['created' => $locked->created_rows, 'updated' => $locked->updated_rows, 'skipped' => $locked->skipped_rows, 'failed' => $locked->failed_rows], ['created', 'updated', 'skipped', 'failed']), $actor));

            return $locked->fresh();
        }, 3);
    }

    private function commitPortable(Studio $studio, User $actor, CrmImportBatch $batch, int $expectedVersion, ?string $commandId): CrmImportBatch
    {
        return DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion, $commandId): CrmImportBatch {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }
            $locked = CrmImportBatch::query()->where('studio_id', $studio->getKey())->where('requested_by_id', $actor->getAuthIdentifier())->lockForUpdate()->findOrFail($batch->getKey());
            Gate::forUser($actor)->authorize('update', $locked);
            if ($commandId !== null && (string) $locked->active_command_id !== $commandId) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_RUN_CONFLICT');
            }
            if ($locked->version !== $expectedVersion || $locked->status !== 'ready') {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_VERSION_CONFLICT');
            }
            if ($locked->row_count > 5000) {
                throw new CrmDataPortabilityConflict('CRM_PORTABLE_ROW_LIMIT_EXCEEDED');
            }
            foreach (['people', 'households', 'instruments', 'tags', 'custom_field_definitions'] as $table) {
                if (DB::table($table)->where('studio_id', $studio->getKey())->exists()) {
                    throw new CrmDataPortabilityConflict('CRM_PORTABLE_TARGET_NOT_EMPTY');
                }
            }
            $this->portable->prepare($locked);
            foreach ($locked->rows()->where('status', 'resolved')->where('decision', 'create')->orderBy('row_number')->lockForUpdate()->get() as $row) {
                $payload = $row->normalized_payload;
                $person = $this->createPerson->handle($this->personAttributes($payload), $actor);
                $this->portable->mapPerson($locked, (string) $payload['_portable_person_ref'], $person->getKey());
                $row->forceFill(['status' => 'created', 'result_person_id' => $person->getKey(), 'result_digest' => hash('sha256', 'created|'.$person->getKey()), 'processed_at' => now()])->save();
            }
            $this->portable->finish($locked, $actor);
            $created = $locked->rows()->where('status', 'created')->count();
            $locked->forceFill(['status' => 'completed', 'processed_rows' => $created, 'created_rows' => $created, 'completed_at' => now(), 'active_command_id' => null, 'version' => $locked->version + 1])->save();
            $this->audit->record(new AuditRecord($studio->getKey(), 'crm.portable_restore.completed', 'crm_import_batch', $locked->getKey(), SafeAuditPayload::from(['created' => $created, 'failed' => 0], ['created', 'failed']), $actor));
            $this->outbox->record(new OutboxRecord($studio->getKey(), 'crm.portable_restore.completed', 'crm_import_batch', $locked->getKey(), 'crm-portable:'.$locked->getKey(), ['import_id' => $locked->getKey(), 'created' => $created]));

            return $locked->fresh();
        }, 5);
    }

    private function claim(Studio $studio, CrmImportBatch $batch): ?CrmImportRow
    {
        return DB::transaction(function () use ($studio, $batch): ?CrmImportRow {
            $row = CrmImportRow::query()->where('studio_id', $studio->getKey())->where('import_batch_id', $batch->getKey())
                ->where(function ($query): void {
                    $query->where('status', 'resolved')->orWhere(fn ($query) => $query->where('status', 'processing')->where('lease_expires_at', '<', now()));
                })
                ->orderBy('row_number')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $token = (string) Str::uuid();
            $row->forceFill(['status' => 'processing', 'lease_token' => $token, 'leased_at' => now(), 'lease_expires_at' => now()->addSeconds((int) config('data-portability.row_lease_seconds', 120)), 'attempts' => $row->attempts + 1])->save();

            return $row->fresh();
        }, 3);
    }

    private function apply(Studio $studio, User $actor, CrmImportBatch $batch, CrmImportRow $row): void
    {
        DB::transaction(function () use ($studio, $actor, $batch, $row): void {
            $locked = CrmImportRow::query()->where('studio_id', $studio->getKey())->where('lease_token', $row->lease_token)->lockForUpdate()->findOrFail($row->getKey());
            $payload = $locked->normalized_payload;
            if ($locked->decision === 'skip') {
                $person = null;
                $household = null;
                $terminal = 'skipped';
            } else {
                if ($locked->decision === 'update') {
                    $candidate = Person::query()->where('studio_id', $studio->getKey())->lockForUpdate()->findOrFail($locked->candidate_person_id);
                    if ($candidate->version !== $locked->candidate_person_version) {
                        throw new CrmDataPortabilityConflict('CRM_IMPORT_CANDIDATE_STALE');
                    }
                    $person = $this->updatePerson->handle($candidate, $this->personAttributes($payload), $candidate->version, $actor);
                    $terminal = 'updated';
                } else {
                    $person = $this->createPerson->handle($this->personAttributes($payload), $actor);
                    $terminal = 'created';
                }
                $household = $locked->candidate_household_id ? Household::query()->where('studio_id', $studio->getKey())->findOrFail($locked->candidate_household_id) : null;
                $household = $this->households->handle($studio, $actor, $person, $payload, $household, $locked->candidate_household_version);
                $this->portable->mapPerson($batch, (string) ($payload['_portable_person_ref'] ?? ''), $person->getKey());
                $this->outbox->record(new OutboxRecord($studio->getKey(), 'crm.person.imported', 'person', $person->getKey(), 'crm-import:'.$locked->getKey(), ['person_id' => $person->getKey(), 'outcome' => $terminal]));
            }
            $locked->forceFill(['status' => $terminal, 'result_person_id' => $person?->getKey(), 'result_household_id' => $household?->getKey(),
                'result_digest' => hash('sha256', implode('|', [$terminal, $person?->getKey(), $household?->getKey()])),
                'lease_token' => null, 'leased_at' => null, 'lease_expires_at' => null, 'processed_at' => now()])->save();
            $this->audit->record(new AuditRecord($studio->getKey(), 'crm.import.row_'.$terminal, 'crm_import_row', $locked->getKey(),
                SafeAuditPayload::from(['row_number' => $locked->row_number, 'outcome' => $terminal], ['row_number', 'outcome']), $actor));
        }, 3);
    }

    private function fail(Studio $studio, CrmImportBatch $batch, CrmImportRow $row, Throwable $exception): void
    {
        DB::transaction(function () use ($studio, $batch, $row, $exception): void {
            CrmImportRow::query()->where('studio_id', $studio->getKey())->where('import_batch_id', $batch->getKey())
                ->whereKey($row->getKey())->where('lease_token', $row->lease_token)->update([
                    'status' => 'failed', 'error_code' => $exception instanceof ValidationException ? 'CRM_IMPORT_ROW_INVALID' : 'CRM_IMPORT_ROW_FAILED',
                    'error_fields' => $exception instanceof ValidationException ? array_keys($exception->errors()) : [],
                    'result_digest' => hash('sha256', get_class($exception).'|'.$exception->getMessage()),
                    'lease_token' => null, 'leased_at' => null, 'lease_expires_at' => null, 'processed_at' => now(), 'updated_at' => now(),
                ]);
        });
    }

    /** @param array<string,string> $payload @return array<string,mixed> */
    private function personAttributes(array $payload): array
    {
        return array_filter([
            'first_name' => $payload['first_name'], 'last_name' => $payload['last_name'] ?: null,
            'preferred_name' => $payload['preferred_name'] ?: null, 'email' => $payload['email'] ?: null,
            'phone' => $payload['phone'] ?: null, 'birth_date' => $payload['birth_date'] ?: null,
            'pronouns' => $payload['pronouns'] ?: null, 'status' => $payload['status'] ?: 'active',
            'source' => 'crm_import', 'external_reference' => $payload['external_reference'] ?: null,
            'preferred_locale' => $payload['preferred_locale'] ?: null,
            'student' => $payload['student_status'] === '' ? null : ['status' => $payload['student_status'], 'joined_on' => $payload['student_joined_on'] ?: null, 'school_grade' => $payload['student_school_grade'] ?: null],
        ], static fn ($value): bool => $value !== null);
    }
}
