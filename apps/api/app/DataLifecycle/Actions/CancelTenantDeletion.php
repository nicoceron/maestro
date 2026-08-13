<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CancelTenantDeletion
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    public function handle(TenantDeletionRequest $request, User $actor): TenantDeletionRequest
    {
        return DB::transaction(function () use ($request, $actor): TenantDeletionRequest {
            $request = TenantDeletionRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status === TenantDeletionStatus::Cancelled) {
                return $request;
            }
            if (! in_array($request->status, [
                TenantDeletionStatus::CoolingOff,
                TenantDeletionStatus::Approved,
            ], true)) {
                throw new DomainException('DELETION_NOT_CANCELLABLE');
            }
            $previous = $request->status->value;
            $request->forceFill([
                'status' => TenantDeletionStatus::Cancelled,
                'cancelled_at' => now(),
                'version' => $request->version + 1,
            ])->save();
            $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.cancelled', $previous, TenantDeletionStatus::Cancelled->value, $actor);

            return $request;
        });
    }
}
