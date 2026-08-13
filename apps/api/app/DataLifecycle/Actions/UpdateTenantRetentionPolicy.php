<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateTenantRetentionPolicy
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Studio $studio, User $actor, array $attributes, ?int $expectedVersion = null): TenantRetentionPolicy
    {
        $allowed = [
            'export_ttl_hours',
            'deletion_cooling_off_days',
            'deletion_quarantine_days',
            'operational_retention_days',
            'media_retention_days',
            'audit_retention_days',
        ];
        $values = array_intersect_key($attributes, array_flip($allowed));

        foreach ($values as $key => $value) {
            $bounds = $key === 'export_ttl_hours' ? [1, 168] : [1, 3650];
            if (! is_int($value) || $value < $bounds[0] || $value > $bounds[1]) {
                throw ValidationException::withMessages([$key => 'The retention value is outside the permitted range.']);
            }
        }

        return DB::transaction(function () use ($studio, $actor, $values, $expectedVersion): TenantRetentionPolicy {
            $policy = TenantRetentionPolicy::query()->firstOrCreate(
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
            $policy = TenantRetentionPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $policy->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'The retention policy was changed by another user.']);
            }
            $policy->forceFill([...$values, 'version' => $policy->version + 1])->save();
            $this->audit->record($policy, 'retention_policy', 'tenant.retention.updated', null, null, $actor, [
                'changed_fields' => array_keys($values),
                'version' => $policy->version,
            ]);

            return $policy;
        });
    }

    public function placeLegalHold(TenantRetentionPolicy $policy, User $actor, string $reason): TenantRetentionPolicy
    {
        return DB::transaction(function () use ($policy, $actor, $reason): TenantRetentionPolicy {
            $policy = TenantRetentionPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            if ($policy->legal_hold) {
                return $policy;
            }
            $policy->forceFill([
                'legal_hold' => true,
                'legal_hold_reason' => $reason,
                'legal_hold_placed_by_id' => $actor->getAuthIdentifier(),
                'legal_hold_placed_at' => now(),
                'legal_hold_released_by_id' => null,
                'legal_hold_released_at' => null,
                'version' => $policy->version + 1,
            ])->save();
            $this->audit->record($policy, 'retention_policy', 'tenant.legal_hold.placed', null, null, $actor, [
                'reason_digest' => hash('sha256', $reason),
            ]);

            return $policy;
        });
    }

    public function releaseLegalHold(TenantRetentionPolicy $policy, User $actor): TenantRetentionPolicy
    {
        return DB::transaction(function () use ($policy, $actor): TenantRetentionPolicy {
            $policy = TenantRetentionPolicy::query()->whereKey($policy->getKey())->lockForUpdate()->firstOrFail();
            if (! $policy->legal_hold) {
                return $policy;
            }
            $policy->forceFill([
                'legal_hold' => false,
                'legal_hold_released_by_id' => $actor->getAuthIdentifier(),
                'legal_hold_released_at' => now(),
                'version' => $policy->version + 1,
            ])->save();
            $this->audit->record($policy, 'retention_policy', 'tenant.legal_hold.released', null, null, $actor);

            return $policy;
        });
    }
}
