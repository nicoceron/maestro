<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Studio;
use App\Support\Scheduling\SchedulingRecordRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreSchedulingRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(SchedulingRecordRegistry $registry): array
    {
        $studio = $this->route('studio');

        abort_unless($studio instanceof Studio, 404);

        return $registry->rules((string) $this->route('resource'), $studio, creating: true);
    }
}
