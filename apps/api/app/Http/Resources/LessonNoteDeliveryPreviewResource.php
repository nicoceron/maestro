<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LessonNoteDeliveryPreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_id' => $this->lesson_note_id,
            'note_version' => $this->note_version,
            'recipient_count' => $this->recipient_projection['recipient_count'],
            'attachments' => $this->recipient_projection['attachments'] ?? [],
            'expires_at' => $this->expires_at->toAtomString(),
            'can_commit' => $this->consumed_at === null && $this->expires_at->isFuture(),
        ];
    }
}
