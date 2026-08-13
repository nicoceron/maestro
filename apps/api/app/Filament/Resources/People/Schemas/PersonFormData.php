<?php

namespace App\Filament\Resources\People\Schemas;

use App\Enums\StudentStatus;
use App\Models\Person;
use Illuminate\Support\Arr;

final class PersonFormData
{
    /** @return array<string, mixed> */
    public static function fill(Person $person): array
    {
        $person->loadMissing([
            'studentProfile',
            'staffProfile',
            'instrumentAssignments',
            'tagAssignments',
            'customFieldValues',
        ]);

        return [
            ...$person->attributesToArray(),
            'version' => $person->version,
            'is_student' => $person->studentProfile !== null,
            'student' => $person->studentProfile?->attributesToArray(),
            'is_staff' => $person->staffProfile !== null,
            'staff' => $person->staffProfile?->attributesToArray(),
            'instruments' => $person->instrumentAssignments
                ->map(fn ($assignment): array => [
                    'instrument_id' => $assignment->instrument_id,
                    'relationship' => $assignment->relationship->value,
                    'proficiency' => $assignment->proficiency?->value,
                    'is_primary' => $assignment->is_primary,
                    'years_experience' => $assignment->years_experience,
                ])
                ->all(),
            'tag_ids' => $person->tagAssignments->pluck('tag_id')->all(),
            'custom_field_inputs' => $person->customFieldValues
                ->mapWithKeys(fn ($field): array => [
                    $field->definition_id => $field->value['value'] ?? null,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forWrite(array $data, ?Person $person = null): array
    {
        $isStudent = (bool) Arr::pull($data, 'is_student', false);
        $isStaff = (bool) Arr::pull($data, 'is_staff', false);
        $customFieldInputs = Arr::pull($data, 'custom_field_inputs', []);
        unset($data['version']);

        if ($isStudent) {
            $student = is_array($data['student'] ?? null) ? $data['student'] : [];

            if ($person?->studentProfile !== null) {
                $student['status'] = $person->studentProfile->status->value;
                $student = array_filter(
                    $student,
                    fn (string $key): bool => in_array($key, [
                        'status',
                        'joined_on',
                        'left_on',
                        'school_grade',
                        'learning_preferences',
                        'lead_source',
                        'trial_started_on',
                        'waitlisted_on',
                    ], true),
                    ARRAY_FILTER_USE_KEY,
                );
            } else {
                $student['status'] ??= StudentStatus::Lead->value;
            }

            $data['student'] = $student;
        } elseif ($person?->studentProfile !== null) {
            // Existing student profiles are historical records and cannot be
            // removed from an ordinary profile edit.
            unset($data['student']);
        } else {
            $data['student'] = null;
        }

        $data['staff'] = $isStaff
            ? (is_array($data['staff'] ?? null) ? $data['staff'] : [])
            : null;
        $data['tag_ids'] = array_values(array_unique(array_filter(
            is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : [],
            'is_string',
        )));
        $data['instruments'] = array_values(
            is_array($data['instruments'] ?? null) ? $data['instruments'] : [],
        );
        $data['custom_fields'] = collect(
            is_array($customFieldInputs) ? $customFieldInputs : [],
        )
            ->map(fn (mixed $value, string $definitionId): array => [
                'definition_id' => $definitionId,
                'value' => $value,
            ])
            ->values()
            ->all();

        return $data;
    }
}
