<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class InvitationTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'invitation_token' => ['required', 'string', 'min:40', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
