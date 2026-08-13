<?php

namespace App\DataLifecycle\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRetentionPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'export_ttl_hours' => ['sometimes', 'integer', 'between:1,168'],
            'deletion_cooling_off_days' => ['sometimes', 'integer', 'between:1,3650'],
            'deletion_quarantine_days' => ['sometimes', 'integer', 'between:1,3650'],
            'operational_retention_days' => ['sometimes', 'integer', 'between:1,3650'],
            'media_retention_days' => ['sometimes', 'integer', 'between:1,3650'],
            'audit_retention_days' => ['sometimes', 'integer', 'between:1,3650'],
        ];
    }
}
