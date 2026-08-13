<?php

namespace App\Http\Resources;

use App\Support\Attachments\LessonNoteAttachmentProjection;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LessonNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $access = app(AttendanceAccess::class);
        $attachments = app(LessonNoteAttachmentProjection::class)
            ->visibleCurrentAttachments($this->resource, $request->user());

        return [
            'id' => $this->id,
            'occurrence_id' => $this->event_occurrence_id,
            'participant_id' => $this->event_occurrence_participant_id,
            'scope' => $this->scope->value,
            'audience' => $this->audience->value,
            'title' => $this->title,
            'body_html' => $this->body_html,
            'current_revision' => $this->current_revision,
            'version' => $this->version,
            'attachments' => LessonNoteAttachmentResource::collection($attachments),
            'permissions' => [
                'edit' => $access->canReviseNote($request->user(), $this->resource),
                'preview_delivery' => $access->canDeliverNote($request->user(), $this->resource),
            ],
        ];
    }
}
