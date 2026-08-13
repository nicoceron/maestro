<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LessonNoteAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['latestScan', 'retirement', 'note', 'revision']);
        $status = $this->retirement !== null
            ? 'retired'
            : ($this->latestScan?->status->value ?? 'pending');

        return [
            'id' => $this->id,
            'note_id' => $this->lesson_note_id,
            'note_revision' => $this->revision->revision,
            'name' => $this->original_name,
            'mime' => $this->detected_mime,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'status' => $status,
            'created_at' => $this->created_at->toAtomString(),
            'permissions' => [
                'download' => $request->user()?->can('download', $this->resource) ?? false,
                'retire' => $request->user()?->can('retire', $this->resource) ?? false,
                'rescan' => $request->user()?->can('rescan', $this->resource) ?? false,
            ],
        ];
    }
}
