<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LessonNoteAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLessonNoteTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:120', 'regex:/\S/u'],
            'audience' => ['sometimes', Rule::enum(LessonNoteAudience::class)],
            'body_html' => ['sometimes', 'string', 'max:20000', 'regex:/\S/u'],
            'active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:500', 'regex:/\S/u'],
        ];
    }
}
