<?php

namespace App\DataLifecycle\Console;

use App\DataLifecycle\Actions\AdvanceTenantDeletionLifecycle;
use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use DomainException;
use Illuminate\Console\Command;

final class AdvanceTenantDeletionLifecycleCommand extends Command
{
    protected $signature = 'tenant-data:advance-deletions';

    protected $description = 'Advance approved tenant deletions through reversible suspension and quarantine';

    public function handle(AdvanceTenantDeletionLifecycle $action, RequestDatabaseContext $context): int
    {
        $advanced = 0;
        Studio::query()->withTrashed()->orderBy('id')->pluck('id')->each(
            function (string $studioId) use ($action, $context, &$advanced): void {
                $context->activateStudioIdForSession($studioId);
                try {
                    TenantDeletionRequest::query()->where('studio_id', $studioId)->whereIn('status', [
                        TenantDeletionStatus::Approved,
                        TenantDeletionStatus::Suspended,
                        TenantDeletionStatus::Quarantined,
                    ])->orderBy('id')->get()->each(function (TenantDeletionRequest $request) use ($action, &$advanced): void {
                        try {
                            $before = $request->status;
                            $after = $action->handle($request)->status;
                            $advanced += $before !== $after ? 1 : 0;
                        } catch (DomainException $exception) {
                            if ($exception->getMessage() !== 'LEGAL_HOLD_ACTIVE') {
                                throw $exception;
                            }
                        }
                    });
                } finally {
                    $context->clearStudio();
                }
            },
        );
        $this->components->info("{$advanced} tenant deletion transitions advanced; no hard deletes were performed.");

        return self::SUCCESS;
    }
}
