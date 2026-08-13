<?php

namespace App\DataPortability\Commands;

use App\Audit\StudioDatabaseScope;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Models\CrmPortableRefMap;
use App\Models\Studio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PurgeCrmDataPortability extends Command
{
    protected $signature = 'crm-data-portability:purge {--studio=}';

    protected $description = 'Expire CRM portability artifacts and physically redact staged row PII.';

    public function handle(StudioDatabaseScope $scope): int
    {
        $query = Studio::withTrashed()->select('id')->orderBy('id');
        if (is_string($this->option('studio')) && $this->option('studio') !== '') {
            $query->whereKey($this->option('studio'));
        }
        $query->eachById(function (Studio $studio) use ($scope): void {
            $scope->run((string) $studio->getKey(), fn () => $this->purgeStudio((string) $studio->getKey()));
        });

        return self::SUCCESS;
    }

    private function purgeStudio(string $studioId): void
    {
        CrmImportBatch::query()->where('studio_id', $studioId)->where('expires_at', '<=', now())->whereNull('purged_at')->whereNotIn('status', ['committing'])->orderBy('id')->each(function (CrmImportBatch $batch): void {
            $path = DB::transaction(function () use ($batch): ?string {
                $locked = CrmImportBatch::query()->where('studio_id', $batch->studio_id)->lockForUpdate()->findOrFail($batch->getKey());
                if ($locked->status === 'expired') {
                    return $locked->quarantine_path;
                }
                if ($locked->status === 'committing' || $locked->rows()->where('status', 'processing')->where('lease_expires_at', '>', now())->exists() || $locked->commands()->whereIn('status', ['queued', 'running'])->exists()) {
                    return null;
                }
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("select set_config('app.crm_portability_purge','1',true)");
                }
                CrmImportRow::query()->where('studio_id', $locked->studio_id)->where('import_batch_id', $locked->getKey())->update([
                    'content_hash' => null, 'normalized_payload' => null, 'candidate_person_id' => null, 'candidate_person_version' => null,
                    'candidate_household_id' => null, 'candidate_household_version' => null, 'match_candidates' => null, 'resolved_by_id' => null,
                    'result_person_id' => null, 'result_household_id' => null, 'result_digest' => null, 'error_fields' => null, 'purged_at' => now(), 'updated_at' => now(),
                ]);
                CrmPortableRefMap::query()->where('studio_id', $locked->studio_id)->where('import_batch_id', $locked->getKey())->delete();
                $locked->forceFill(['status' => 'expired', 'version' => $locked->version + 1])->save();

                return $locked->quarantine_path;
            });
            if ($path === null) {
                return;
            }$disk = Storage::disk((string) config('data-portability.disk'));
            if ($disk->exists($path) && ! $disk->delete($path)) {
                return;
            }
            DB::transaction(function () use ($batch): void {
                $locked = CrmImportBatch::query()->where('studio_id', $batch->studio_id)->where('status', 'expired')->lockForUpdate()->find($batch->getKey());
                if ($locked) {
                    $locked->forceFill(['status' => 'purged', 'quarantine_path' => 'purged', 'purged_at' => now(), 'version' => $locked->version + 1])->save();
                }
            });
        });
        CrmPortableExport::query()->where('studio_id', $studioId)->where('expires_at', '<=', now())->whereNull('purged_at')->orderBy('id')->each(function (CrmPortableExport $export): void {
            $path = DB::transaction(function () use ($export): ?string {
                $locked = CrmPortableExport::query()->where('studio_id', $export->studio_id)->lockForUpdate()->findOrFail($export->getKey());
                if ($locked->status === 'expired') {
                    return is_string($locked->archive_path) ? $locked->archive_path : null;
                }
                if (in_array($locked->status, ['queued', 'building'], true)) {
                    return null;
                }
                $path = $locked->archive_path;
                $locked->forceFill(['status' => 'expired', 'manifest' => null, 'version' => $locked->version + 1])->save();

                return is_string($path) ? $path : null;
            });
            if ($path === null) {
                DB::transaction(function () use ($export): void {
                    $locked = CrmPortableExport::query()->where('studio_id', $export->studio_id)->where('status', 'expired')->lockForUpdate()->find($export->getKey());
                    if ($locked && ! is_string($locked->archive_path)) {
                        $locked->forceFill(['status' => 'purged', 'purged_at' => now(), 'version' => $locked->version + 1])->save();
                    }
                });

                return;
            }$disk = Storage::disk((string) config('data-portability.disk'));
            if ($disk->exists($path) && ! $disk->delete($path)) {
                return;
            }
            DB::transaction(function () use ($export): void {
                $locked = CrmPortableExport::query()->where('studio_id', $export->studio_id)->where('status', 'expired')->lockForUpdate()->find($export->getKey());
                if ($locked) {
                    $locked->forceFill(['status' => 'purged', 'archive_path' => null, 'archive_sha256' => null, 'archive_size' => null, 'purged_at' => now(), 'version' => $locked->version + 1])->save();
                }
            });
        });
        $this->purgeOrphans($studioId);
    }

    private function purgeOrphans(string $studioId): void
    {
        $disk = Storage::disk((string) config('data-portability.disk'));
        $referenced = array_flip(array_filter([
            ...CrmImportBatch::query()->where('studio_id', $studioId)->whereNull('purged_at')->pluck('quarantine_path')->all(),
            ...CrmPortableExport::query()->where('studio_id', $studioId)->whereNull('purged_at')->pluck('archive_path')->all(),
        ], 'is_string'));
        $cutoff = now()->subHour()->timestamp;
        $scanned = 0;
        foreach ([...$disk->files("quarantine/{$studioId}"), ...$disk->files("exports/{$studioId}")] as $path) {
            if (++$scanned > 1000) {
                break;
            }if (! isset($referenced[$path]) && $disk->lastModified($path) <= $cutoff) {
                $disk->delete($path);
            }
        }
    }
}
