<?php

namespace App\DataPortability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveCrmImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['import_version' => ['required', 'integer', 'min:1'], 'rows' => ['required', 'array', 'min:1', 'max:200'], 'rows.*.row_id' => ['required', 'ulid'], 'rows.*.plan_version' => ['required', 'integer', 'min:1'], 'rows.*.decision' => ['required', 'in:create,update,skip'], 'rows.*.candidate_person_id' => ['nullable', 'ulid'], 'rows.*.candidate_person_version' => ['nullable', 'integer', 'min:1'], 'rows.*.candidate_household_id' => ['nullable', 'ulid'], 'rows.*.candidate_household_version' => ['nullable', 'integer', 'min:1']];
    }
}
