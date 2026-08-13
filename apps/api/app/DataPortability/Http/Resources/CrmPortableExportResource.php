<?php

namespace App\DataPortability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmPortableExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(), 'version' => $this->version, 'status' => $this->status,
            'format_version' => $this->format_version, 'manifest' => $this->safeManifest(),
            'archive_size' => $this->archive_size, 'download_count' => $this->download_count,
            'ready_at' => $this->ready_at?->toIso8601String(), 'expires_at' => $this->expires_at?->toIso8601String(),
            'error_code' => $this->error_code,
        ];
    }

    private function safeManifest(): ?array
    {
        if (! is_array($this->manifest)) {
            return null;
        }

        return [
            'schema' => $this->manifest['schema'] ?? null, 'version' => $this->manifest['version'] ?? null,
            'studio' => $this->manifest['studio'] ?? null, 'canonicalization' => $this->manifest['canonicalization'] ?? null,
            'datasets' => collect($this->manifest['datasets'] ?? [])->map(fn (array $dataset): array => [
                'name' => $dataset['name'] ?? null, 'media_type' => $dataset['media_type'] ?? null,
                'columns' => $dataset['columns'] ?? [], 'row_count' => $dataset['row_count'] ?? 0,
                'dependencies' => $dataset['dependencies'] ?? [],
            ])->all(), 'excluded' => $this->manifest['excluded'] ?? [],
        ];
    }
}
