<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EventAssignmentRole;
use App\Enums\ScheduleEditScope;
use App\Models\Studio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PreviewScheduleChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $studio = $this->route('studio');
        $exists = fn (string $table) => Rule::exists($table, 'id')
            ->where('studio_id', $studio instanceof Studio ? $studio->getKey() : null);

        return [
            'version' => ['required', 'integer', 'min:1'],
            'scope' => ['required', Rule::enum(ScheduleEditScope::class)],
            'starts_at_local' => ['sometimes', 'date_format:Y-m-d\TH:i:s'],
            'start_resolution' => ['sometimes', Rule::in(['reject', 'earlier', 'later'])],
            'dtstart_local' => ['sometimes', 'date_format:Y-m-d\TH:i:s', 'prohibits:starts_at_local'],
            'dtstart_resolution' => ['required_with:dtstart_local', Rule::in(['reject', 'earlier', 'later']), 'prohibits:start_resolution'],
            'location_id' => ['sometimes', 'nullable', 'ulid', $exists('locations')],
            'timezone' => ['sometimes', 'timezone:all'],
            'duration_minutes' => ['sometimes', 'integer', 'between:5,1440'],
            'title' => ['sometimes', 'string', 'max:160'],
            'capacity' => ['sometimes', 'integer', 'between:1,1000'],
            'visibility' => ['sometimes', Rule::in(['private', 'studio', 'portal', 'public'])],
            'shared_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'internal_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'makeup_required' => ['sometimes', 'boolean'],
            'makeup_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'teachers' => ['sometimes', 'array', 'max:20'],
            'teachers.*.staff_profile_id' => ['required', 'ulid', 'distinct', $exists('staff_profiles')],
            'teachers.*.role' => ['sometimes', Rule::enum(EventAssignmentRole::class)],
            'room_ids' => ['sometimes', 'array', 'max:20'],
            'room_ids.*' => ['ulid', 'distinct', $exists('rooms')],
            'equipment' => ['sometimes', 'array', 'max:50'],
            'equipment.*.equipment_id' => ['required', 'ulid', 'distinct', $exists('equipment')],
            'equipment.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'acknowledge_soft_warnings' => ['sometimes', 'boolean'],
            'rrule' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'rdates' => ['sometimes', 'array', 'max:200'],
            'rdates.*.local' => ['required', 'date_format:Y-m-d\TH:i:s', 'distinct'],
            'rdates.*.resolution' => ['required', Rule::in(['reject', 'earlier', 'later'])],
            'exdates' => ['sometimes', 'array', 'max:200'],
            'exdates.*' => ['date_format:Y-m-d\TH:i:s', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (($this->hasAny(['starts_at_local', 'dtstart_local', 'location_id', 'timezone']))
                && ! $this->hasAny(['start_resolution', 'dtstart_resolution'])) {
                $validator->errors()->add('start_resolution', 'A daylight-saving resolution is required when changing time, timezone, or location.');
            }
        });
    }
}
