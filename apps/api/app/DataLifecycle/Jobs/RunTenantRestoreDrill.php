<?php

namespace App\DataLifecycle\Jobs;

use App\DataLifecycle\Enums\TenantRestoreDrillStatus;
use App\DataLifecycle\Models\TenantRestoreDrill;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Support\Tenancy\RequestDatabaseContext;
use App\TenantData\Support\TenantExportBuilder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use JsonException;
use RuntimeException;
use Throwable;

final class RunTenantRestoreDrill implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $drillId,
        public readonly string $studioId,
    ) {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->drillId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tenant-restore-drill:{$this->drillId}"))->expireAfter(180)];
    }

    public function handle(
        TenantExportBuilder $builder,
        TenantDataLifecycleAudit $audit,
        RequestDatabaseContext $context,
    ): void {
        $context->activateStudioIdForSession($this->studioId);
        try {
            $drill = TenantRestoreDrill::query()->findOrFail($this->drillId);
            if ($drill->status === TenantRestoreDrillStatus::Verified) {
                return;
            }
            $drill->forceFill(['status' => TenantRestoreDrillStatus::Running, 'started_at' => now()])->save();
            $archive = $builder->readAndVerify($drill->export);
            $sourceStudioId = (string) ($archive['manifest']['studio_id'] ?? '');
            if (! hash_equals((string) $drill->studio_id, $sourceStudioId)) {
                throw new RuntimeException('TENANT_INVARIANT_MISMATCH');
            }

            $rows = 0;
            $sourceReferences = 0;
            foreach ($archive['datasets'] as $dataset => $contents) {
                foreach (array_filter(explode("\n", $contents), fn (string $line): bool => $line !== '') as $line) {
                    try {
                        $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new RuntimeException("DATASET_INVALID_JSON:{$dataset}", previous: $exception);
                    }
                    if (! is_array($row)) {
                        throw new RuntimeException("DATASET_ROW_INVALID:{$dataset}");
                    }
                    if (array_key_exists('studio_id', $row)) {
                        $sourceReferences++;
                        if (! hash_equals($sourceStudioId, (string) $row['studio_id'])) {
                            throw new RuntimeException("TENANT_INVARIANT_MISMATCH:{$dataset}");
                        }
                    }
                    if ($dataset === 'studios' && ! hash_equals($sourceStudioId, (string) ($row['id'] ?? ''))) {
                        throw new RuntimeException('TENANT_ROOT_INVARIANT_MISMATCH');
                    }
                    $rows++;
                }
            }

            $verification = [
                'archive_checksum_valid' => true,
                'manifest_checksum_valid' => true,
                'dataset_checksums_valid' => true,
                'schema_version_supported' => true,
                'tenant_invariants_valid' => true,
                'tenant_id_remap_valid' => count($drill->tenant_id_remap ?? []) === 1,
                'dataset_count' => count($archive['datasets']),
                'row_count' => $rows,
                'tenant_reference_count' => $sourceReferences,
                'dry_run' => true,
            ];
            $drill->forceFill([
                'status' => TenantRestoreDrillStatus::Verified,
                'verification' => $verification,
                'completed_at' => now(),
                'error_code' => null,
                'failure_digest' => null,
            ])->save();
            $audit->record($drill, 'tenant_restore_drill', 'tenant.restore_drill.verified', TenantRestoreDrillStatus::Running->value, TenantRestoreDrillStatus::Verified->value, null, $verification);
        } finally {
            $context->clearStudio();
        }
    }

    public function failed(Throwable $exception): void
    {
        $context = app(RequestDatabaseContext::class);
        $context->activateStudioIdForSession($this->studioId);
        try {
            TenantRestoreDrill::query()->whereKey($this->drillId)->update([
                'status' => TenantRestoreDrillStatus::Failed->value,
                'error_code' => str_contains($exception->getMessage(), ':')
                    ? explode(':', $exception->getMessage(), 2)[0]
                    : $exception->getMessage(),
                'failure_digest' => hash('sha256', $exception::class."\0".$exception->getMessage()),
                'completed_at' => now(),
            ]);
        } finally {
            $context->clearStudio();
        }
    }
}
