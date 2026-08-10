<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:140',
                'alpha_dash:ascii',
                Rule::notIn(['admin', 'api', 'app', 'login', 'manage', 'platform', 'settings', 'support', 'www']),
                Rule::unique('studios', 'slug'),
            ],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'locale' => ['sometimes', 'string', Rule::in(['de', 'en', 'es', 'fr', 'ja', 'nl'])],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'week_starts_on' => ['sometimes', 'integer', 'between:0,6'],
        ];
    }
}
