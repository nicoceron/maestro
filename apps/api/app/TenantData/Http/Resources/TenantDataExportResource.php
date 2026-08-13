<?php

namespace App\TenantData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantDataExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'status' => $this->status->value,
            'format_version' => $this->format_version,
            'include_media_inventory' => $this->include_media_inventory,
            'progress' => [
                'completed_datasets' => count($this->completed_datasets ?? []),
                'next_dataset_index' => $this->next_dataset_index,
            ],
            'manifest' => $this->when(
                $this->status->value === 'ready',
                fn (): array => $this->safeManifest($this->manifest ?? []),
            ),
            'archive_size' => $this->archive_size,
            'ready_at' => $this->ready_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'purged_at' => $this->purged_at?->toIso8601String(),
            'download_count' => $this->download_count,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function safeManifest(array $manifest): array
    {
        return [
            'format' => $manifest['format'] ?? null,
            'format_version' => $manifest['format_version'] ?? null,
            'export_id' => $manifest['export_id'] ?? null,
            'created_at' => $manifest['created_at'] ?? null,
            'snapshot_boundary' => $manifest['snapshot_boundary'] ?? null,
            'application' => $manifest['application'] ?? null,
            'studio' => $manifest['studio'] ?? null,
            'canonicalization' => $manifest['canonicalization'] ?? null,
            'excluded_secret_fields' => $manifest['excluded_secret_fields'] ?? [],
            'media_inventory_included' => $manifest['media_inventory_included'] ?? false,
            'datasets' => collect($manifest['datasets'] ?? [])->map(fn (array $dataset): array => [
                'name' => $dataset['name'] ?? null,
                'path' => $dataset['path'] ?? null,
                'media' => $dataset['media'] ?? false,
                'dependencies' => $dataset['dependencies'] ?? [],
                'schema_version' => $dataset['schema_version'] ?? null,
                'count' => $dataset['count'] ?? 0,
                'sha256' => $dataset['sha256'] ?? null,
                'bytes' => $dataset['bytes'] ?? 0,
            ])->values()->all(),
            'manifest_sha256' => $manifest['manifest_sha256'] ?? null,
        ];
    }
}
