<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PreviewCloneEventSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => Str::squish((string) $this->input('title'))]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:160'],
            'dtstart_local' => ['required', 'date_format:Y-m-d\TH:i:s'],
            'dtstart_resolution' => ['required', Rule::in(['reject', 'earlier', 'later'])],
            'timezone' => ['sometimes', 'timezone:all'],
            'rrule' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'acknowledge_soft_warnings' => ['sometimes', 'boolean'],
            'copy_roster' => ['prohibited'],
        ];
    }
}
