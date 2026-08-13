<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

final class UpdateHouseholdRequest extends StoreHouseholdRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            ...$this->householdRules(creating: false),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateHousehold($validator, creating: false);
    }
}
