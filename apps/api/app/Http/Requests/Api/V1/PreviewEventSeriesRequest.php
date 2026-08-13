<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\EventAssignmentRole;
use App\Enums\EventKind;
use App\Enums\EventVisibility;
use App\Enums\LocalTimeResolution;
use App\Models\EventSeries;
use App\Models\Studio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PreviewEventSeriesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => Str::squish((string) $this->input('title'))]);
        }
    }

    public function authorize(): bool
    {
        $studio = $this->route('studio');

        return $studio instanceof Studio && ($this->user()?->can('create', [EventSeries::class, $studio]) ?? false);
    }

    public function rules(): array
    {
        $studioId = $this->route('studio')?->getKey();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('studio_id', $studioId);

        return [
            'service_id' => ['nullable', 'ulid', $exists('services')],
            'program_offering_id' => ['nullable', 'ulid', $exists('program_offerings')],
            'location_id' => ['nullable', 'ulid', $exists('locations')],
            'pricing_staff_profile_id' => ['nullable', 'ulid', $exists('staff_profiles')],
            'kind' => ['required', Rule::enum(EventKind::class)],
            'visibility' => ['sometimes', Rule::enum(EventVisibility::class)],
            'title' => ['required', 'string', 'max:160'],
            'shared_description' => ['nullable', 'string', 'max:10000'],
            'internal_description' => ['nullable', 'string', 'max:10000'],
            'timezone' => ['required', 'timezone:all'],
            'dtstart_local' => ['required', 'date_format:Y-m-d\TH:i:s'],
            'dtstart_resolution' => ['sometimes', Rule::in([
                LocalTimeResolution::Reject->value, LocalTimeResolution::Earlier->value, LocalTimeResolution::Later->value,
            ])],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
            'rrule' => ['nullable', 'string', 'max:1000'],
            'rdates' => ['sometimes', 'array', 'max:200'],
            'rdates.*.local' => ['required', 'date_format:Y-m-d\TH:i:s', 'distinct'],
            'rdates.*.resolution' => ['required', Rule::in(['reject', 'earlier', 'later'])],
            'exdates' => ['sometimes', 'array', 'max:200'],
            'exdates.*' => ['date_format:Y-m-d\TH:i:s', 'distinct'],
            'capacity' => ['required', 'integer', 'between:1,1000'],
            'hold_expires_at' => ['nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays(7)->toAtomString()],
            'teachers' => ['sometimes', 'array', 'max:20'],
            'teachers.*.staff_profile_id' => ['required', 'ulid', 'distinct', $exists('staff_profiles')],
            'teachers.*.role' => ['sometimes', Rule::enum(EventAssignmentRole::class)],
            'room_ids' => ['sometimes', 'array', 'max:20'],
            'room_ids.*' => ['ulid', 'distinct', $exists('rooms')],
            'equipment' => ['sometimes', 'array', 'max:50'],
            'equipment.*.equipment_id' => ['required', 'ulid', 'distinct', $exists('equipment')],
            'equipment.*.quantity' => ['required', 'integer', 'between:1,1000'],
            'acknowledge_soft_warnings' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $kind = EventKind::tryFrom((string) $this->input('kind'));

            if (! in_array($kind, [EventKind::General, EventKind::Closure], true) && ! $this->filled('service_id')) {
                $validator->errors()->add('service_id', 'Teaching and performance events require a service.');
            }

            if (($this->input('room_ids', []) !== [] || $this->input('equipment', []) !== []) && ! $this->filled('location_id')) {
                $validator->errors()->add('location_id', 'Rooms and equipment require a location.');
            }

            $pricingTeacher = $this->input('pricing_staff_profile_id');
            $assigned = array_column($this->input('teachers', []), 'staff_profile_id');

            if ($pricingTeacher !== null && ! in_array($pricingTeacher, $assigned, true)) {
                $validator->errors()->add('pricing_staff_profile_id', 'The pricing teacher must be assigned to the series.');
            }
        });
    }
}
