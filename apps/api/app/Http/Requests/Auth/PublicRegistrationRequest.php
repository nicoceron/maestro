<?php

namespace App\Http\Requests\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class PublicRegistrationRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:2', 'max:120'],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254'],
            'password' => $this->passwordRules(),
            'invitation_token' => ['nullable', 'string', 'min:40', 'max:128'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => User::normalizeEmail((string) $this->input('email', '')),
        ]);
    }
}
