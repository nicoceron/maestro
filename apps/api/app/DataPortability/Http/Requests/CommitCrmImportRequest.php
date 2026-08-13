<?php

namespace App\DataPortability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CommitCrmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['import_version' => ['required', 'integer', 'min:1']];
    }
}
