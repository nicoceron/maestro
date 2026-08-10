<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PrimaryGoal;
use App\Enums\WorkspaceMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'invitation_token' => [
                'nullable',
                'string',
                'min:40',
                'max:128',
                'required_without:studio',
                'prohibits:studio',
            ],
            'studio' => [
                'nullable',
                'array',
                'required_without:invitation_token',
                'prohibits:invitation_token',
            ],
            'studio.name' => ['required_with:studio', 'string', 'min:2', 'max:120'],
            'studio.slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:140',
                'alpha_dash:ascii',
                Rule::notIn(['admin', 'api', 'app', 'login', 'manage', 'platform', 'settings', 'support', 'www']),
                Rule::unique('studios', 'slug'),
            ],
            'studio.timezone' => ['sometimes', 'string', 'timezone:all'],
            'studio.locale' => ['sometimes', 'string', Rule::in(['de', 'en', 'es', 'fr', 'ja', 'nl'])],
            'studio.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'studio.week_starts_on' => ['sometimes', 'integer', 'between:0,6'],
            'preferred_name' => ['nullable', 'string', 'max:80'],
            'workspace_mode' => ['nullable', Rule::enum(WorkspaceMode::class)],
            'primary_goal' => ['nullable', Rule::enum(PrimaryGoal::class)],
            'role' => ['prohibited'],
            'studio.role' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('preferred_name')) {
            $this->merge(['preferred_name' => trim((string) $this->input('preferred_name'))]);
        }
    }
}
