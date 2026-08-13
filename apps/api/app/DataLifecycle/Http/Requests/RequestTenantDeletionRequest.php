<?php

namespace App\DataLifecycle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestTenantDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'reason' => ['required', 'string', 'min:12', 'max:2000'],
            'confirmation_phrase' => ['required', 'string', 'max:180'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
