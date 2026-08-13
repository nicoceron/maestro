<?php

namespace App\DataLifecycle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:12', 'max:2000']];
    }
}
