<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Studio;
use App\Support\Scheduling\SchedulingRecordRegistry;

final class UpdateSchedulingRecordRequest extends StoreSchedulingRecordRequest
{
    /** @return array<string, mixed> */
    public function rules(SchedulingRecordRegistry $registry): array
    {
        $studio = $this->route('studio');

        abort_unless($studio instanceof Studio, 404);

        return [
            'version' => ['required', 'integer', 'min:1'],
            ...$registry->rules((string) $this->route('resource'), $studio, creating: false),
        ];
    }
}
