<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStudioInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => [
                'required',
                Rule::enum(MembershipRole::class)->only([
                    MembershipRole::Administrator,
                    MembershipRole::Office,
                    MembershipRole::Billing,
                    MembershipRole::Teacher,
                ]),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => User::normalizeEmail((string) $this->input('email'))]);
        }
    }
}
