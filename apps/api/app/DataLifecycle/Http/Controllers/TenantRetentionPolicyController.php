<?php

namespace App\DataLifecycle\Http\Controllers;

use App\DataLifecycle\Actions\UpdateTenantRetentionPolicy;
use App\DataLifecycle\Http\Requests\LegalHoldRequest;
use App\DataLifecycle\Http\Requests\UpdateRetentionPolicyRequest;
use App\DataLifecycle\Http\Resources\TenantRetentionPolicyResource;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\Models\Studio;
use Illuminate\Support\Facades\Gate;

final class TenantRetentionPolicyController
{
    public function show(Studio $studio): TenantRetentionPolicyResource
    {
        $policy = $this->policy($studio);
        Gate::authorize('view', $policy);

        return new TenantRetentionPolicyResource($policy);
    }

    public function update(
        UpdateRetentionPolicyRequest $request,
        Studio $studio,
        UpdateTenantRetentionPolicy $action,
    ): TenantRetentionPolicyResource {
        $policy = $this->policy($studio);
        Gate::authorize('update', $policy);
        $updated = $action->handle(
            $studio,
            $request->user(),
            $request->safe()->except('version'),
            $request->integer('version'),
        );

        return new TenantRetentionPolicyResource($updated);
    }

    public function placeLegalHold(
        LegalHoldRequest $request,
        Studio $studio,
        UpdateTenantRetentionPolicy $action,
    ): TenantRetentionPolicyResource {
        $policy = $this->policy($studio);
        Gate::authorize('placeLegalHold', $policy);

        return new TenantRetentionPolicyResource(
            $action->placeLegalHold($policy, $request->user(), $request->string('reason')->toString()),
        );
    }

    public function releaseLegalHold(
        Studio $studio,
        UpdateTenantRetentionPolicy $action,
    ): TenantRetentionPolicyResource {
        $policy = $this->policy($studio);
        Gate::authorize('releaseLegalHold', $policy);

        return new TenantRetentionPolicyResource($action->releaseLegalHold($policy, auth()->user()));
    }

    private function policy(Studio $studio): TenantRetentionPolicy
    {
        return TenantRetentionPolicy::query()->firstOrCreate(
            ['studio_id' => $studio->getKey()],
            [
                'export_ttl_hours' => config('tenant-data.export_ttl_hours'),
                'deletion_cooling_off_days' => config('tenant-data.cooling_off_days'),
                'deletion_quarantine_days' => config('tenant-data.quarantine_days'),
                'operational_retention_days' => config('tenant-data.default_retention_days'),
                'media_retention_days' => config('tenant-data.default_retention_days'),
                'audit_retention_days' => config('tenant-data.default_retention_days'),
            ],
        );
    }
}
