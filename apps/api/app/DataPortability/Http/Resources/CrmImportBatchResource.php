<?php

namespace App\DataPortability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CrmImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(), 'version' => $this->version, 'status' => $this->status,
            'schema' => ['name' => $this->schema_name, 'version' => $this->schema_version, 'encoding' => $this->encoding],
            'file_name' => $this->original_name,
            'summary' => ['rows' => $this->row_count, 'processed' => $this->processed_rows, 'creates' => $this->created_rows, 'updates' => $this->updated_rows, 'skipped' => $this->skipped_rows, 'conflicts' => $this->conflicted_rows, 'failed' => $this->failed_rows],
            'mapping' => $this->column_mapping,
            'previewed_at' => $this->previewed_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(), 'error_code' => $this->error_code,
        ];
    }
}
