<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Enums\MembershipStatus;
use App\Enums\StudioStatus;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RestoreTenantDeletion
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    public function handle(TenantDeletionRequest $request, User $actor): TenantDeletionRequest
    {
        return DB::transaction(function () use ($request, $actor): TenantDeletionRequest {
            $request = TenantDeletionRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status === TenantDeletionStatus::Restored) {
                return $request;
            }
            if (! in_array($request->status, [
                TenantDeletionStatus::Suspended,
                TenantDeletionStatus::Quarantined,
                TenantDeletionStatus::PurgeEligible,
                TenantDeletionStatus::Failed,
            ], true)) {
                throw new DomainException('TENANT_NOT_RESTORABLE');
            }

            $previous = $request->status->value;
            $request->forceFill(['status' => TenantDeletionStatus::Restoring, 'version' => $request->version + 1])->save();
            $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.restoring', $previous, TenantDeletionStatus::Restoring->value, $actor);

            $snapshot = $request->verification['pre_suspension'] ?? [];
            $studioStatus = StudioStatus::tryFrom((string) ($snapshot['studio_status'] ?? 'active')) ?? StudioStatus::Active;
            $request->studio()->withTrashed()->firstOrFail()->forceFill(['status' => $studioStatus, 'deleted_at' => null])->save();
            foreach (($snapshot['membership_statuses'] ?? []) as $id => $status) {
                DB::table('studio_memberships')
                    ->where('studio_id', $request->studio_id)
                    ->where('id', $id)
                    ->update([
                        'status' => MembershipStatus::tryFrom((string) $status)?->value ?? MembershipStatus::Suspended->value,
                        'updated_at' => now(),
                    ]);
            }

            $request->forceFill([
                'status' => TenantDeletionStatus::Restored,
                'restored_at' => now(),
                'version' => $request->version + 1,
            ])->save();
            $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.restored', TenantDeletionStatus::Restoring->value, TenantDeletionStatus::Restored->value, $actor);

            return $request;
        });
    }
}
