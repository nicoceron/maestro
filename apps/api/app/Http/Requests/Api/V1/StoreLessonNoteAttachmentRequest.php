<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreLessonNoteAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $types = array_keys((array) config('lesson-notes.attachments.allowed_types', []));
        $maximumKilobytes = max(1, (int) ceil(((int) config('lesson-notes.attachments.maximum_bytes')) / 1024));

        return [
            'note_version' => ['required', 'integer', 'min:1'],
            'file' => [
                'required',
                File::types($types)->max($maximumKilobytes),
                'extensions:'.implode(',', $types),
            ],
        ];
    }
}
