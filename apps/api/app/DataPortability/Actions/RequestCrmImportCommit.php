<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Jobs\CommitCrmImportJob;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportCommand;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RequestCrmImportCommit
{
    public function handle(Studio $studio, User $actor, CrmImportBatch $batch, int $expectedVersion, string $idempotencyKey, string $type = 'commit'): CrmImportCommand
    {
        Gate::forUser($actor)->authorize('update', $batch);
        $fingerprint = hash('sha256', implode('|', [$studio->getKey(), $batch->getKey(), $actor->getAuthIdentifier(), $expectedVersion, $type]));

        return DB::transaction(function () use ($studio, $actor, $batch, $expectedVersion, $idempotencyKey, $type, $fingerprint): CrmImportCommand {
            DB::table('studios')->where('id', $studio->getKey())->lockForUpdate()->firstOrFail();
            $existing = CrmImportCommand::query()->where('studio_id', $studio->getKey())->where('requested_by_id', $actor->getAuthIdentifier())
                ->where('type', $type)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new CrmDataPortabilityConflict('IDEMPOTENCY_KEY_REUSED');
                }

                return $existing;
            }
            $locked = CrmImportBatch::query()->where('studio_id', $studio->getKey())->where('requested_by_id', $actor->getAuthIdentifier())->lockForUpdate()->findOrFail($batch->getKey());
            if ($locked->version !== $expectedVersion) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_VERSION_CONFLICT');
            }
            if ($locked->expires_at->isPast() || $locked->purged_at !== null) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_EXPIRED');
            }
            if ($locked->active_command_id !== null) {
                throw new CrmDataPortabilityConflict('CRM_IMPORT_RUN_ALREADY_ACTIVE');
            }
            $command = CrmImportCommand::query()->create(['studio_id' => $studio->getKey(), 'import_batch_id' => $locked->getKey(), 'requested_by_id' => $actor->getAuthIdentifier(), 'type' => $type, 'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint, 'expected_version' => $expectedVersion, 'status' => 'queued']);
            $locked->forceFill(['active_command_id' => $command->getKey()])->save();
            DB::afterCommit(fn () => CommitCrmImportJob::dispatch($studio->getKey(), $command->getKey()));

            return $command;
        }, 3);
    }
}
