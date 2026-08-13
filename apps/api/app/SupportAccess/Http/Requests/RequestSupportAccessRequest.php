<?php

namespace App\SupportAccess\Http\Requests;

use App\SupportAccess\SupportScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestSupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'studio_id' => ['required', 'string', 'exists:studios,id'],
            'scopes' => ['required', 'array', 'min:1', 'max:5'],
            'scopes.*' => ['required', 'distinct', Rule::enum(SupportScope::class)],
            'reason' => ['required', 'string', 'min:12', 'max:500'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = trim((string) $this->header('Idempotency-Key'));
        abort_if($key === '' || strlen($key) > 160, 422, 'A valid Idempotency-Key header is required.');

        return $key;
    }
}
