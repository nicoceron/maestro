<?php

namespace App\TenantData\Support;

use App\TenantData\Models\TenantDataExport;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class TenantExportBuilder
{
    public function __construct(
        private readonly TenantExportCatalog $catalog,
        private readonly TenantArchiveCipher $cipher,
    ) {}

    /** @return array{name:string, schema_version:string, count:int, sha256:string, bytes:int} */
    public function exportDataset(TenantDataExport $export, string $table): array
    {
        $connection = DB::connection();
        $columns = $this->catalog->exportableColumns($connection, $table);
        $query = $connection->table($table)->select($columns);

        if ($table === 'studios') {
            $query->where('id', $export->studio_id);
        } else {
            $query->where('studio_id', $export->studio_id);
        }
        if (in_array('updated_at', $columns, true)) {
            $query->where('updated_at', '<=', $export->created_at);
        } elseif (in_array('created_at', $columns, true)) {
            $query->where('created_at', '<=', $export->created_at);
        } elseif (in_array('occurred_at', $columns, true)) {
            $query->where('occurred_at', '<=', $export->created_at);
        }

        $orderColumns = $this->stableOrderColumns($connection, $table, $columns);
        foreach ($orderColumns as $column) {
            $query->orderBy($column);
        }

        $plaintext = '';
        $count = 0;

        foreach ($query->cursor() as $row) {
            $data = [];
            foreach ($columns as $column) {
                $data[$column] = $this->normalizeValue($row->{$column} ?? null);
            }
            $plaintext .= json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
            $count++;
        }

        $path = $this->chunkPath($export, $table);
        $this->disk()->put($path, $this->cipher->encrypt($plaintext));

        return [
            'name' => $table,
            'path' => "data/{$table}.ndjson",
            'media' => $table === 'lesson_note_attachments',
            'dependencies' => [],
            'schema_version' => TenantExportCatalog::FORMAT_VERSION,
            'count' => $count,
            'sha256' => hash('sha256', $plaintext),
            'bytes' => strlen($plaintext),
        ];
    }

    /** @param list<array{name:string, path:string, media:bool, dependencies:list<string>, schema_version:string, count:int, sha256:string, bytes:int}> $datasets
     * @return array<string, mixed>
     */
    public function finalize(TenantDataExport $export, array $datasets): array
    {
        usort($datasets, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $manifest = [
            'format' => 'maestro-tenant-portable-archive',
            'format_version' => TenantExportCatalog::FORMAT_VERSION,
            'studio_id' => (string) $export->studio_id,
            'export_id' => (string) $export->getKey(),
            'created_at' => Carbon::parse($export->created_at)->utc()->toIso8601ZuluString(),
            'snapshot_boundary' => Carbon::parse($export->created_at)->utc()->toIso8601ZuluString(),
            'application' => ['name' => 'maestro', 'schema' => '2026-08-13.1'],
            'studio' => [
                'timezone' => $export->studio->timezone,
                'locale' => $export->studio->locale,
                'currency' => $export->studio->currency,
            ],
            'canonicalization' => 'UTF-8; JSON keys sorted by schema; NDJSON LF; null preserved',
            'excluded_secret_fields' => $this->catalog->forbiddenColumnNames(),
            'media_inventory_included' => $export->include_media_inventory,
            'datasets' => $datasets,
        ];
        $manifest['manifest_sha256'] = hash(
            'sha256',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $entries = [];
        foreach ($datasets as $dataset) {
            $ciphertext = $this->disk()->get($this->chunkPath($export, $dataset['name']));
            $entries[] = [
                'name' => $dataset['name'],
                'path' => $dataset['path'],
                'content_base64' => base64_encode($this->cipher->decrypt($ciphertext)),
            ];
        }
        $plaintextArchive = json_encode([
            'manifest' => $manifest,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ciphertextArchive = $this->cipher->encrypt($plaintextArchive);
        $archivePath = "tenant-exports/{$export->studio_id}/{$export->getKey()}.maestro.json.enc";
        $this->disk()->put($archivePath, $ciphertextArchive);

        foreach ($datasets as $dataset) {
            $this->disk()->delete($this->chunkPath($export, $dataset['name']));
        }

        return [
            'manifest' => $manifest,
            'archive_path' => $archivePath,
            'archive_ciphertext_sha256' => hash('sha256', $ciphertextArchive),
            'archive_size' => strlen($ciphertextArchive),
        ];
    }

    /** @return array{manifest:array<string, mixed>, datasets:array<string, string>} */
    public function readAndVerify(TenantDataExport $export): array
    {
        $ciphertext = $this->disk()->get((string) $export->archive_path);
        if (! hash_equals((string) $export->archive_ciphertext_sha256, hash('sha256', $ciphertext))) {
            throw new RuntimeException('ARCHIVE_CHECKSUM_MISMATCH');
        }

        try {
            $archive = json_decode($this->cipher->decrypt($ciphertext), true, flags: JSON_THROW_ON_ERROR);
            $manifest = $archive['manifest'] ?? null;
            if (! is_array($manifest) || ($manifest['format_version'] ?? null) !== TenantExportCatalog::FORMAT_VERSION) {
                throw new RuntimeException('SCHEMA_VERSION_UNSUPPORTED');
            }
            $expectedManifestHash = (string) ($manifest['manifest_sha256'] ?? '');
            unset($manifest['manifest_sha256']);
            $actualManifestHash = hash('sha256', json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            if (! hash_equals($expectedManifestHash, $actualManifestHash)) {
                throw new RuntimeException('MANIFEST_CHECKSUM_MISMATCH');
            }
            $manifest['manifest_sha256'] = $expectedManifestHash;

            $datasets = [];
            $declared = collect($manifest['datasets'] ?? [])->keyBy('name');
            $seen = [];
            foreach ($archive['entries'] ?? [] as $entry) {
                $name = (string) ($entry['name'] ?? '');
                $path = (string) ($entry['path'] ?? '');
                if ($name === '' || isset($seen[$name])) {
                    throw new RuntimeException('ARCHIVE_DUPLICATE_ENTRY');
                }
                if ($path !== "data/{$name}.ndjson" || str_contains($path, '..') || str_starts_with($path, '/')) {
                    throw new RuntimeException('ARCHIVE_UNSAFE_PATH');
                }
                if (! $declared->has($name)) {
                    throw new RuntimeException('ARCHIVE_UNDECLARED_ENTRY');
                }
                $content = base64_decode((string) ($entry['content_base64'] ?? ''), true);
                if (! is_string($content)) {
                    throw new RuntimeException("DATASET_INVALID_ENCODING:{$name}");
                }
                $datasets[$name] = $content;
                $seen[$name] = true;
            }
            foreach ($manifest['datasets'] ?? [] as $dataset) {
                $name = (string) ($dataset['name'] ?? '');
                $contents = $datasets[$name] ?? null;
                if (! is_string($contents) || ! hash_equals((string) ($dataset['sha256'] ?? ''), hash('sha256', $contents))) {
                    throw new RuntimeException("DATASET_CHECKSUM_MISMATCH:{$name}");
                }
                if (strlen($contents) !== (int) ($dataset['bytes'] ?? -1)) {
                    throw new RuntimeException("DATASET_BYTES_MISMATCH:{$name}");
                }
                $lineCount = $contents === '' ? 0 : substr_count($contents, "\n");
                if ($lineCount !== (int) ($dataset['count'] ?? -1)) {
                    throw new RuntimeException("DATASET_COUNT_MISMATCH:{$name}");
                }
                $datasets[$name] = $contents;
            }

            return ['manifest' => $manifest, 'datasets' => $datasets];
        } catch (JsonException $exception) {
            throw new RuntimeException('MANIFEST_INVALID_JSON', previous: $exception);
        }
    }

    public function decryptedArchive(TenantDataExport $export): string
    {
        $ciphertext = $this->disk()->get((string) $export->archive_path);

        if (! hash_equals((string) $export->archive_ciphertext_sha256, hash('sha256', $ciphertext))) {
            throw new RuntimeException('ARCHIVE_CHECKSUM_MISMATCH');
        }

        return $this->cipher->decrypt($ciphertext);
    }

    public function cleanup(TenantDataExport $export): void
    {
        if (is_string($export->archive_path) && $export->archive_path !== '') {
            $this->disk()->delete($export->archive_path);
        }
        foreach ($this->catalog->datasetTables(DB::connection()) as $table) {
            $this->disk()->delete($this->chunkPath($export, $table));
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('tenant-data.disk', 'tenant_exports'));
    }

    private function chunkPath(TenantDataExport $export, string $table): string
    {
        return "tenant-exports/{$export->studio_id}/{$export->getKey()}/chunks/{$table}.ndjson.enc";
    }

    /** @param list<string> $columns
     * @return list<string>
     */
    private function stableOrderColumns(ConnectionInterface $connection, string $table, array $columns): array
    {
        foreach (['id', 'created_at', 'studio_id'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return [$candidate];
            }
        }

        return $columns === [] ? [] : [$columns[0]];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_resource($value)) {
            return base64_encode(stream_get_contents($value) ?: '');
        }

        return $value;
    }
}
