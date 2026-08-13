<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Enums\EmploymentType;
use App\Enums\InstrumentRelationship;
use App\Enums\PersonStatus;
use App\Enums\ProficiencyLevel;
use App\Enums\StaffRole;
use App\Enums\StaffStatus;
use App\Enums\StudentStatus;
use App\Models\Studio;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ValidatesPersonPayload
{
    /** @return array<string, mixed> */
    private function personRules(bool $creating): array
    {
        $studio = $this->route('studio');
        $externalReference = Rule::unique('people', 'external_reference');

        if ($studio instanceof Studio) {
            $externalReference->where('studio_id', $studio->getKey());
        }

        if (! $creating && is_string($this->route('person'))) {
            $externalReference->ignore($this->route('person'));
        }

        return [
            'first_name' => [$creating ? 'required' : 'sometimes', 'filled', 'string', 'max:100', 'regex:/\S/u'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'pronouns' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::enum(PersonStatus::class)],
            'source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:120', $externalReference],
            'preferred_locale' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/'],

            'student' => ['sometimes', 'nullable', 'array'],
            'student.status' => [$creating ? 'required_with:student' : 'sometimes', Rule::enum(StudentStatus::class)],
            'student.joined_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'student.left_on' => ['prohibited'],
            'student.school_grade' => ['sometimes', 'nullable', 'string', 'max:60'],
            'student.learning_preferences' => ['sometimes', 'array', 'max:20'],
            'student.learning_preferences.*' => ['string', 'max:100', 'distinct'],
            'student.lead_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'student.trial_started_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'student.waitlisted_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],

            'staff' => ['sometimes', 'nullable', 'array'],
            'staff.roles' => ['required_with:staff', 'array', 'min:1', 'max:3'],
            'staff.roles.*' => [Rule::enum(StaffRole::class), 'distinct'],
            'staff.status' => ['required_with:staff', Rule::enum(StaffStatus::class)],
            'staff.employment_type' => ['sometimes', 'nullable', Rule::enum(EmploymentType::class)],
            'staff.bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'staff.hire_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'staff.left_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'staff.can_substitute' => ['sometimes', 'boolean'],

            'tag_ids' => ['sometimes', 'array', 'max:100'],
            'tag_ids.*' => ['string', 'ulid', 'distinct'],
            'instruments' => ['sometimes', 'array', 'max:50'],
            'instruments.*.instrument_id' => ['required', 'string', 'ulid', 'distinct'],
            'instruments.*.relationship' => ['required', Rule::enum(InstrumentRelationship::class)],
            'instruments.*.proficiency' => ['sometimes', 'nullable', Rule::enum(ProficiencyLevel::class)],
            'instruments.*.is_primary' => ['sometimes', 'boolean'],
            'instruments.*.years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'custom_fields' => ['sometimes', 'array', 'max:100'],
            'custom_fields.*.definition_id' => ['required', 'string', 'ulid', 'distinct'],
            'custom_fields.*.value' => ['present'],
        ];
    }

    private function preparePersonForValidation(): void
    {
        if ($this->has('external_reference') && is_string($this->input('external_reference'))) {
            $reference = Str::squish($this->string('external_reference')->toString());
            $this->merge(['external_reference' => $reference === '' ? null : $reference]);
        }
    }
}
