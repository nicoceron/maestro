<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CommitSchedulePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'command' => ['prohibited'],
            'acknowledge_soft_warnings' => ['prohibited'],
        ];
    }
}
