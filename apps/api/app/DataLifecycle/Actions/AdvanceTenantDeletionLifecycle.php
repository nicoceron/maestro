<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\StudioStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

final class AdvanceTenantDeletionLifecycle
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    public function handle(TenantDeletionRequest $request): TenantDeletionRequest
    {
        return DB::transaction(function () use ($request): TenantDeletionRequest {
            $request = TenantDeletionRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $policy = TenantRetentionPolicy::query()->where('studio_id', $request->studio_id)->lockForUpdate()->firstOrFail();
            if ($policy->legal_hold) {
                throw new DomainException('LEGAL_HOLD_ACTIVE');
            }

            return match ($request->status) {
                TenantDeletionStatus::Approved => $this->suspend($request),
                TenantDeletionStatus::Suspended => $this->quarantine($request, $policy),
                TenantDeletionStatus::Quarantined => $request->purge_eligible_at?->isPast()
                    ? $this->markPurgeEligible($request)
                    : $request,
                default => $request,
            };
        });
    }

    private function suspend(TenantDeletionRequest $request): TenantDeletionRequest
    {
        $studio = $request->studio()->lockForUpdate()->firstOrFail();
        $originalStudioStatus = $studio->status;
        $memberships = DB::table('studio_memberships')
            ->where('studio_id', $request->studio_id)
            ->pluck('status', 'id')
            ->all();
        DB::table('studio_memberships')
            ->where('studio_id', $request->studio_id)
            ->where('role', '!=', MembershipRole::Owner->value)
            ->update(['status' => MembershipStatus::Suspended->value, 'updated_at' => now()]);
        $studio->forceFill(['status' => StudioStatus::Suspended])->save();

        $verification = $request->verification ?? [];
        $verification['pre_suspension'] = [
            'studio_status' => $originalStudioStatus->value,
            'membership_statuses' => $memberships,
        ];
        $previous = $request->status->value;
        $request->forceFill([
            'status' => TenantDeletionStatus::Suspended,
            'suspended_at' => now(),
            'verification' => $verification,
            'version' => $request->version + 1,
        ])->save();
        $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.suspended', $previous, TenantDeletionStatus::Suspended->value, null);

        return $request;
    }

    private function quarantine(TenantDeletionRequest $request, TenantRetentionPolicy $policy): TenantDeletionRequest
    {
        $previous = $request->status->value;
        $request->forceFill([
            'status' => TenantDeletionStatus::Quarantined,
            'quarantined_at' => now(),
            'purge_eligible_at' => now()->addDays($policy->deletion_quarantine_days),
            'version' => $request->version + 1,
        ])->save();
        $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.quarantined', $previous, TenantDeletionStatus::Quarantined->value, null, [
            'purge_eligible_at' => $request->purge_eligible_at->toIso8601String(),
        ]);

        return $request;
    }

    private function markPurgeEligible(TenantDeletionRequest $request): TenantDeletionRequest
    {
        $previous = $request->status->value;
        $request->forceFill([
            'status' => TenantDeletionStatus::PurgeEligible,
            'version' => $request->version + 1,
        ])->save();
        $this->audit->record($request, 'tenant_deletion', 'tenant.deletion.purge_eligible', $previous, TenantDeletionStatus::PurgeEligible->value, null, [
            'hard_delete_performed' => false,
        ]);

        return $request;
    }
}
