<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LessonNoteAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLessonNoteTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'audience' => ['required', Rule::enum(LessonNoteAudience::class)],
            'body_html' => ['required', 'string', 'max:20000', 'regex:/\S/u'],
        ];
    }
}
