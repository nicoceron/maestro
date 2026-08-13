<?php

namespace App\TenantData\Jobs;

use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Support\Tenancy\RequestDatabaseContext;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Models\TenantDataExport;
use App\TenantData\Support\TenantExportBuilder;
use App\TenantData\Support\TenantExportCatalog;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BuildTenantDataExport implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 300];

    public int $timeout = 120;

    public function __construct(
        public readonly string $exportId,
        public readonly string $studioId,
    ) {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("tenant-export:{$this->exportId}"))->expireAfter(180)];
    }

    public function handle(
        TenantExportBuilder $builder,
        TenantExportCatalog $catalog,
        TenantDataLifecycleAudit $audit,
        RequestDatabaseContext $databaseContext,
    ): void {
        $databaseContext->activateStudioIdForSession($this->studioId);
        try {
            $this->build($builder, $catalog, $audit);
        } finally {
            $databaseContext->clearStudio();
        }
    }

    private function build(TenantExportBuilder $builder, TenantExportCatalog $catalog, TenantDataLifecycleAudit $audit): void
    {
        $export = TenantDataExport::query()->findOrFail($this->exportId);
        if (in_array($export->status, [
            TenantDataExportStatus::Ready,
            TenantDataExportStatus::Expired,
            TenantDataExportStatus::Purged,
        ], true)) {
            return;
        }

        $tables = $catalog->datasetTables(DB::connection());
        if (! $export->include_media_inventory) {
            $tables = array_values(array_diff($tables, ['lesson_note_attachments']));
        }

        $connection = DB::connection();
        $previousIsolation = $connection->getConfig('isolation_level');
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        try {
            DB::transaction(function () use ($export, $tables, $builder, $audit): void {
                $completed = $export->completed_datasets ?? [];
                $startIndex = min($export->next_dataset_index, count($tables));
                $export->forceFill(['status' => TenantDataExportStatus::Exporting])->save();

                for ($index = $startIndex; $index < count($tables); $index++) {
                    $metadata = $builder->exportDataset($export, $tables[$index]);
                    $completed = array_values(array_filter(
                        $completed,
                        fn (array $dataset): bool => ($dataset['name'] ?? null) !== $metadata['name'],
                    ));
                    $completed[] = $metadata;
                    $export->forceFill([
                        'completed_datasets' => $completed,
                        'next_dataset_index' => $index + 1,
                    ])->save();
                }

                $archive = $builder->finalize($export, $completed);
                $previous = $export->status->value;
                $export->forceFill([
                    ...$archive,
                    'status' => TenantDataExportStatus::Ready,
                    'ready_at' => now(),
                    'error_code' => null,
                    'failure_digest' => null,
                ])->save();
                $audit->record(
                    $export,
                    'tenant_data_export',
                    'tenant.export.ready',
                    $previous,
                    TenantDataExportStatus::Ready->value,
                    null,
                    ['dataset_count' => count($completed), 'archive_size' => $export->archive_size],
                );
            }, 3);
        } finally {
            if ($connection->getDriverName() === 'pgsql') {
                $level = is_string($previousIsolation) && $previousIsolation !== ''
                    ? strtoupper($previousIsolation)
                    : 'READ COMMITTED';
                $connection->unprepared("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL {$level}");
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $databaseContext = app(RequestDatabaseContext::class);
        $databaseContext->activateStudioIdForSession($this->studioId);

        try {
            $export = TenantDataExport::query()->find($this->exportId);
            if ($export === null || $export->status === TenantDataExportStatus::Ready) {
                return;
            }
            $export->forceFill([
                'status' => TenantDataExportStatus::Failed,
                'error_code' => 'EXPORT_BUILD_FAILED',
                'failure_digest' => hash('sha256', $exception::class."\0".$exception->getMessage()),
            ])->save();
        } finally {
            $databaseContext->clearStudio();
        }
    }
}
