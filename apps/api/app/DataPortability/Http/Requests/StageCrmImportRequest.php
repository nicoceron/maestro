<?php

namespace App\DataPortability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StageCrmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:10240']];
    }
}
