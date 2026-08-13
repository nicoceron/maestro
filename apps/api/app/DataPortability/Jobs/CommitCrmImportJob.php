<?php

namespace App\DataPortability\Jobs;

use App\Audit\StudioDatabaseScope;
use App\DataPortability\Actions\CommitCrmImport;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportCommand;
use App\DataPortability\Support\CrmDataPortabilityAccess;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CommitCrmImportJob implements ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(public string $studioId, public string $commandId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('crm-import-command:'.$this->commandId))->expireAfter(960)];
    }

    public function handle(StudioDatabaseScope $scope, TenantContext $context, CommitCrmImport $commit): void
    {
        $scope->run($this->studioId, function () use ($context, $commit): void {
            $command = CrmImportCommand::query()->where('studio_id', $this->studioId)->findOrFail($this->commandId);
            if ($command->status === 'succeeded') {
                return;
            }
            $studio = Studio::query()->find($this->studioId);
            $actor = User::query()->find($command->requested_by_id);
            $membership = $actor ? StudioMembership::query()->where('studio_id', $this->studioId)->where('user_id', $actor->getAuthIdentifier())->where('status', 'active')->first() : null;
            if (! $studio || ! $actor || ! $membership || ! CrmDataPortabilityAccess::allows($actor, $studio)) {
                $this->failCommand($command, 'CRM_IMPORT_AUTHORIZATION_REVOKED');

                return;
            }
            $context->activate($studio, $membership);
            try {
                $command->update(['status' => 'running', 'started_at' => now()]);
                $batch = CrmImportBatch::query()->where('studio_id', $this->studioId)->findOrFail($command->import_batch_id);
                $result = $commit->handle($studio, $actor, $batch, $command->expected_version, $command->getKey());
                if ($result->status === 'committing') {
                    CommitCrmImportJob::dispatch($this->studioId, $this->commandId)->afterCommit();
                } else {
                    $command->update(['status' => 'succeeded', 'response_snapshot' => ['id' => $result->getKey(), 'version' => $result->version, 'status' => $result->status], 'completed_at' => now()]);
                }
            } catch (Throwable $e) {
                $this->failCommand($command, 'CRM_IMPORT_COMMIT_FAILED');
                throw $e;
            } finally {
                $context->clear();
            }
        });
    }

    private function failCommand(CrmImportCommand $command, string $code): void
    {
        DB::transaction(function () use ($command, $code): void {
            $locked = CrmImportCommand::query()->where('studio_id', $command->studio_id)->lockForUpdate()->findOrFail($command->getKey());
            $locked->forceFill(['status' => 'failed', 'error_code' => $code, 'completed_at' => now()])->save();
            CrmImportBatch::query()->where('studio_id', $command->studio_id)->whereKey($command->import_batch_id)->where('active_command_id', $command->getKey())->update(['active_command_id' => null, 'updated_at' => now()]);
        });
    }
}
