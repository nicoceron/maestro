<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LessonNoteDeliveryIntentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_id' => $this->lesson_note_id,
            'note_version' => $this->note_version,
            'recipient_count' => $this->recipient_projection['recipient_count'],
            'attachments' => $this->recipient_projection['attachments'] ?? [],
            'status' => $this->status->value,
            'committed_at' => $this->committed_at->toAtomString(),
        ];
    }
}
