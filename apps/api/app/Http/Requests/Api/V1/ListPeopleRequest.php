<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PersonStatus;
use App\Enums\StaffRole;
use App\Enums\StudentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::enum(PersonStatus::class)],
            'student_status' => ['sometimes', Rule::enum(StudentStatus::class)],
            'staff_role' => ['sometimes', Rule::enum(StaffRole::class)],
            'instrument_id' => ['sometimes', 'string', 'ulid'],
            'tag_id' => ['sometimes', 'string', 'ulid'],
            'source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
