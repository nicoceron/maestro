<?php

namespace App\SupportAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DecideSupportAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => [$this->routeIs('*approve') ? 'nullable' : 'required', 'string', 'min:4', 'max:500'],
        ];
    }
}
