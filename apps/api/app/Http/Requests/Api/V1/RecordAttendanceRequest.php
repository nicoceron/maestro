<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AttendanceOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(AttendanceOutcome::class)],
            'billing_disposition' => ['prohibited'],
            'makeup_disposition' => ['prohibited'],
            'minutes_late' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'correction_reason' => ['sometimes', 'string', 'max:500', 'regex:/\S/u'],
        ];
    }
}
