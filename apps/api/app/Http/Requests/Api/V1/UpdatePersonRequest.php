<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPersonPayload;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePersonRequest extends FormRequest
{
    use ValidatesPersonPayload;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->preparePersonForValidation();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            ...$this->personRules(creating: false),
        ];
    }
}
