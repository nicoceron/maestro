<?php

namespace App\TenantData\Actions;

use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Models\TenantDataExport;
use App\TenantData\Support\TenantExportBuilder;

final class ExpireTenantDataExports
{
    public function __construct(
        private readonly TenantExportBuilder $builder,
        private readonly TenantDataLifecycleAudit $audit,
        private readonly RequestDatabaseContext $databaseContext,
    ) {}

    public function handle(): int
    {
        $count = 0;
        Studio::query()->withTrashed()->orderBy('id')->pluck('id')->each(function (string $studioId) use (&$count): void {
            $this->databaseContext->activateStudioIdForSession($studioId);
            try {
                $count += $this->expireStudio($studioId);
            } finally {
                $this->databaseContext->clearStudio();
            }
        });

        return $count;
    }

    private function expireStudio(string $studioId): int
    {
        if (TenantRetentionPolicy::query()
            ->where('studio_id', $studioId)
            ->where('legal_hold', true)
            ->exists()) {
            return 0;
        }

        $count = 0;
        TenantDataExport::query()
            ->where('studio_id', $studioId)
            ->whereIn('status', [TenantDataExportStatus::Ready, TenantDataExportStatus::Failed])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$count): void {
                foreach ($exports as $export) {
                    $previous = $export->status->value;
                    $this->builder->cleanup($export);
                    $export->forceFill([
                        'status' => TenantDataExportStatus::Purged,
                        'archive_path' => null,
                        'purged_at' => now(),
                    ])->save();
                    $this->audit->record(
                        $export,
                        'tenant_data_export',
                        'tenant.export.purged',
                        $previous,
                        TenantDataExportStatus::Purged->value,
                        null,
                    );
                    $count++;
                }
            });

        return $count;
    }
}
