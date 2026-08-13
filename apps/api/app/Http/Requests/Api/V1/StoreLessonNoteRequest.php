<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LessonNoteAudience;
use App\Enums\LessonNoteScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLessonNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(LessonNoteScope::class)],
            'participant_id' => ['required_if:scope,participant', 'prohibited_if:scope,group', 'nullable', 'ulid'],
            'audience' => ['required', Rule::enum(LessonNoteAudience::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body_html' => ['required', 'string', 'max:20000', 'regex:/\S/u'],
        ];
    }
}
