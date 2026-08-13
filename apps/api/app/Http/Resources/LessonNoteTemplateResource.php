<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LessonNoteTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'audience' => $this->audience->value,
            'body_html' => $this->body_html,
            'active' => $this->active,
            'version' => $this->version,
        ];
    }
}
