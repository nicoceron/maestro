<?php

namespace App\DataPortability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListCrmImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', 'string', 'in:resolved,conflict,processing,created,updated,skipped,failed'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'cursor' => ['sometimes', 'string', 'max:1024']];
    }
}
