<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\DataPortability\Support\CrmPortableBundle;
use App\DataPortability\Support\CrmPortableCsv;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StageCrmImport
{
    public function __construct(private CrmPortableCsv $csv, private CrmPortableBundle $bundle) {}

    public function handle(Studio $studio, User $actor, UploadedFile $file, string $idempotencyKey): CrmImportBatch
    {
        Gate::forUser($actor)->authorize('create', [CrmImportBatch::class, $studio]);
        $bytes = $file->get();
        if (! is_string($bytes)) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_UPLOAD_UNREADABLE']);
        }
        $isBundle = $this->bundle->isBundle($bytes);
        $extension = mb_strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (($isBundle && ($extension !== 'maestro' || ! in_array($detected, ['application/json', 'text/plain'], true))) || (! $isBundle && ($extension !== 'csv' || ! in_array($detected, ['text/plain', 'text/csv', 'application/csv'], true)))) {
            throw ValidationException::withMessages(['file' => 'CRM_IMPORT_FILE_PROFILE_INVALID']);
        }
        $parsed = $isBundle ? $this->bundle->parse($bytes) : $this->csv->parse($bytes);
        $fingerprint = hash('sha256', implode('|', [$studio->getKey(), $actor->getAuthIdentifier(), hash('sha256', $bytes)]));
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($file->getClientOriginalName())) ?: 'people.csv';

        $disk = Storage::disk((string) config('data-portability.disk'));
        $path = 'quarantine/'.$studio->getKey().'/'.Str::uuid().($isBundle ? '.maestro' : '.csv');
        $disk->put($path, $bytes);

        try {
            return DB::transaction(function () use ($studio, $actor, $idempotencyKey, $fingerprint, $safeName, $bytes, $parsed, $path, $disk, $isBundle): CrmImportBatch {
                DB::table('studios')->where('id', $studio->getKey())->lockForUpdate()->firstOrFail();
                $existing = CrmImportBatch::query()
                    ->where('studio_id', $studio->getKey())
                    ->where('requested_by_id', $actor->getAuthIdentifier())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    $disk->delete($path);
                    if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw new CrmDataPortabilityConflict('IDEMPOTENCY_KEY_REUSED');
                    }

                    return $existing;
                }

                return CrmImportBatch::query()->create([
                    'studio_id' => $studio->getKey(), 'requested_by_id' => $actor->getAuthIdentifier(),
                    'idempotency_key' => $idempotencyKey, 'request_fingerprint' => $fingerprint,
                    'status' => 'staged', 'schema_name' => $isBundle ? 'maestro.crm-portability' : CrmPortableCsv::SCHEMA,
                    'portable_formula_escaping' => $isBundle || $parsed['portable'],
                    'original_name' => mb_substr($safeName, 0, 255), 'quarantine_path' => $path,
                    'source_sha256' => hash('sha256', $bytes), 'source_size' => strlen($bytes),
                    'row_count' => $isBundle ? count($parsed['datasets']['people']) : count($parsed['rows']),
                    'column_mapping' => $isBundle ? ['datasets' => array_keys($parsed['datasets'])] : ['source_columns' => $parsed['columns']],
                    'expires_at' => now()->addHours((int) config('data-portability.artifact_ttl_hours', 48)),
                ])->refresh();
            }, 3);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }
}
