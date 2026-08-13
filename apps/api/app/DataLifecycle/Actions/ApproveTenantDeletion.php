<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\User;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Models\TenantDataExport;
use App\TenantData\Support\TenantExportBuilder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApproveTenantDeletion
{
    public function __construct(
        private readonly TenantDataLifecycleAudit $audit,
        private readonly TenantExportBuilder $exports,
    ) {}

    public function handle(TenantDeletionRequest $request, User $actor): TenantDeletionRequest
    {
        return DB::transaction(function () use ($request, $actor): TenantDeletionRequest {
            $request = TenantDeletionRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            if ($request->status === TenantDeletionStatus::Approved) {
                return $request;
            }
            if ($request->status !== TenantDeletionStatus::CoolingOff) {
                throw new DomainException('DELETION_NOT_APPROVABLE');
            }
            if ($request->cooling_off_ends_at->isFuture()) {
                throw new DomainException('COOLING_OFF_ACTIVE');
            }
            if ($request->requested_by_id === $actor->getAuthIdentifier()) {
                throw new DomainException('SECOND_OWNER_APPROVAL_REQUIRED');
            }
            if (! $actor->studios()
                ->whereKey($request->studio_id)
                ->wherePivot('status', MembershipStatus::Active->value)
                ->wherePivot('role', MembershipRole::Administrator->value)
                ->exists()) {
                throw new DomainException('INDEPENDENT_APPROVAL_REQUIRED');
            }
            $policy = TenantRetentionPolicy::query()->where('studio_id', $request->studio_id)->firstOrFail();
            if ($policy->legal_hold) {
                throw new DomainException('LEGAL_HOLD_ACTIVE');
            }
            $export = TenantDataExport::query()->whereKey($request->export_id)->lockForUpdate()->first();
            if ($export?->status !== TenantDataExportStatus::Ready
                || $export->expires_at?->isPast() !== false
                || ! is_string($export->archive_path)
                || $export->archive_path === '') {
                throw new DomainException('VERIFIED_EXPORT_REQUIRED');
            }
            try {
                $this->exports->readAndVerify($export);
            } catch (Throwable) {
                throw new DomainException('VERIFIED_EXPORT_REQUIRED');
            }

            $previous = $request->status->value;
            $request->forceFill([
                'status' => TenantDeletionStatus::Approved,
                'approved_by_id' => $actor->getAuthIdentifier(),
                'approved_at' => now(),
                'version' => $request->version + 1,
            ])->save();
            $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.approved', $previous, TenantDeletionStatus::Approved->value, $actor);

            return $request;
        });
    }
}
