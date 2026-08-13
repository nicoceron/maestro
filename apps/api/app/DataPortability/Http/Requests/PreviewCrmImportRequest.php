<?php

namespace App\DataPortability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewCrmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['import_version' => ['required', 'integer', 'min:1'], 'mapping' => ['sometimes', 'array', 'max:64'], 'mapping.*' => ['string', 'max:128'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:1024']];
    }
}
