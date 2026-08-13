<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPersonPayload;
use Illuminate\Foundation\Http\FormRequest;

final class StorePersonRequest extends FormRequest
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
        return $this->personRules(creating: true);
    }
}
