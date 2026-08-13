<?php

namespace App\TenantData\Actions;

use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Jobs\BuildTenantDataExport;
use App\TenantData\Models\TenantDataExport;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RequestTenantDataExport
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    public function handle(
        Studio $studio,
        User $actor,
        string $idempotencyKey,
        bool $includeMediaInventory = false,
    ): TenantDataExport {
        $fingerprint = hash('sha256', json_encode([
            'studio_id' => (string) $studio->getKey(),
            'include_media_inventory' => $includeMediaInventory,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($studio, $actor, $idempotencyKey, $includeMediaInventory, $fingerprint): TenantDataExport {
            $existing = TenantDataExport::query()
                ->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DomainException('IDEMPOTENCY_KEY_REUSED');
                }

                return $existing;
            }

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
            $export = TenantDataExport::query()->create([
                'studio_id' => $studio->getKey(),
                'requested_by_id' => $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'include_media_inventory' => $includeMediaInventory,
                'status' => TenantDataExportStatus::Queued,
                'format_version' => '1.0',
                'completed_datasets' => [],
                'expires_at' => now()->addHours($policy->export_ttl_hours),
            ]);
            $this->audit->record(
                $export,
                'tenant_data_export',
                'tenant.export.requested',
                null,
                TenantDataExportStatus::Queued->value,
                $actor,
                ['include_media_inventory' => $includeMediaInventory],
            );
            BuildTenantDataExport::dispatch($export->getKey(), (string) $studio->getKey())->afterCommit();

            return $export;
        });
    }
}
