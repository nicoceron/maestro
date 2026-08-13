<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EventEnrollmentStatus;
use App\Models\Studio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewEventEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $studio = $this->route('studio');

        return [
            'person_id' => [
                'required',
                'ulid',
                Rule::exists('people', 'id')->where('studio_id', $studio instanceof Studio ? $studio->getKey() : null),
            ],
            'status' => ['required', Rule::in([EventEnrollmentStatus::Confirmed->value, EventEnrollmentStatus::Waitlisted->value])],
        ];
    }
}
