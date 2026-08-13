<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityEnforcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAvailabilityOverrideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'approval_status' => ['required', Rule::enum(ApprovalStatus::class), Rule::notIn(['pending'])],
            'enforcement' => ['sometimes', Rule::enum(AvailabilityEnforcement::class)],
        ];
    }
}
