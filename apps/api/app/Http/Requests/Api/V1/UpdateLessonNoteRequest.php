<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateLessonNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'body_html' => ['sometimes', 'string', 'max:20000', 'regex:/\S/u'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
