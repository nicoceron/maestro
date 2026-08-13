<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AttendanceOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordAttendanceBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:250'],
            'items.*.participant_id' => ['required', 'ulid', 'distinct'],
            'items.*.outcome' => ['required', Rule::enum(AttendanceOutcome::class)],
            'items.*.billing_disposition' => ['prohibited'],
            'items.*.makeup_disposition' => ['prohibited'],
            'items.*.minutes_late' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'items.*.reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items.*.version' => ['sometimes', 'integer', 'min:1'],
            'items.*.correction_reason' => ['sometimes', 'string', 'max:500', 'regex:/\S/u'],
        ];
    }
}
