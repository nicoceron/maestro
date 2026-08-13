<?php

namespace App\Actions\People;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Instrument;
use App\Models\Person;
use App\Models\PersonInstrument;
use App\Models\PersonTag;
use App\Models\StaffProfile;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class SyncPersonProfiles
{
    /** @param array<string, mixed> $attributes */
    public function handle(Person $person, array $attributes, User $actor, bool $creating = false): void
    {
        $this->syncStudent($person, $attributes, $actor, $creating);
        $this->syncStaff($person, $attributes);
        $this->syncTags($person, $attributes);
        $this->syncInstruments($person, $attributes);
        $this->syncCustomFields($person, $attributes, $creating);
    }

    /** @param array<string, mixed> $attributes */
    private function syncStudent(Person $person, array $attributes, User $actor, bool $creating): void
    {
        if (! array_key_exists('student', $attributes)) {
            return;
        }

        $student = $attributes['student'];

        if ($student === null) {
            if (! $creating && $person->studentProfile()->exists()) {
                throw ValidationException::withMessages([
                    'student' => 'A student profile cannot be removed. Move it to former instead.',
                ]);
            }

            return;
        }

        if (! is_array($student)) {
            throw ValidationException::withMessages(['student' => 'The student profile is invalid.']);
        }

        $profile = $person->studentProfile()->first();

        if ($profile === null) {
            if (! array_key_exists('status', $student)) {
                throw ValidationException::withMessages([
                    'student.status' => 'The student status field is required when creating a student profile.',
                ]);
            }

            $now = now();
            $profile = StudentProfile::query()->create([
                'studio_id' => $person->studio_id,
                'person_id' => $person->getKey(),
                'status' => $student['status'],
                'joined_on' => $student['joined_on'] ?? null,
                'school_grade' => $student['school_grade'] ?? null,
                'learning_preferences' => $student['learning_preferences'] ?? [],
                'lead_source' => $student['lead_source'] ?? null,
                'trial_started_on' => $student['trial_started_on'] ?? null,
                'waitlisted_on' => $student['waitlisted_on'] ?? null,
                'status_changed_at' => $now,
            ]);
            StudentStatusTransition::query()->create([
                'studio_id' => $person->studio_id,
                'student_profile_id' => $profile->getKey(),
                'person_id' => $person->getKey(),
                'actor_id' => $actor->getAuthIdentifier(),
                'previous_status' => null,
                'new_status' => $profile->status,
                'reason' => 'Student profile created.',
                'occurred_at' => $now,
            ]);

            return;
        }

        if (array_key_exists('status', $student)
            && $student['status'] !== $profile->status->value) {
            throw ValidationException::withMessages([
                'student.status' => 'Use the student status transition action to change lifecycle state.',
            ]);
        }

        $profile->fill(Arr::only($student, [
            'joined_on',
            'school_grade',
            'learning_preferences',
            'lead_source',
            'trial_started_on',
            'waitlisted_on',
        ]))->save();
    }

    /** @param array<string, mixed> $attributes */
    private function syncStaff(Person $person, array $attributes): void
    {
        if (! array_key_exists('staff', $attributes)) {
            return;
        }

        $staff = $attributes['staff'];

        if ($staff === null) {
            if ($person->staffProfile()->exists()) {
                throw ValidationException::withMessages([
                    'staff' => 'A staff profile cannot be removed. Move it to former instead.',
                ]);
            }

            return;
        }

        if (! is_array($staff)) {
            throw ValidationException::withMessages(['staff' => 'The staff profile is invalid.']);
        }

        StaffProfile::query()->updateOrCreate(
            ['studio_id' => $person->studio_id, 'person_id' => $person->getKey()],
            Arr::only($staff, [
                'roles',
                'status',
                'employment_type',
                'bio',
                'hire_on',
                'left_on',
                'can_substitute',
            ]),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function syncTags(Person $person, array $attributes): void
    {
        if (! array_key_exists('tag_ids', $attributes)) {
            return;
        }

        $ids = array_values(array_unique($attributes['tag_ids'] ?? []));
        $previouslyAssigned = $person->tagAssignments()
            ->whereIn('tag_id', $ids)
            ->pluck('tag_id');
        $tags = Tag::query()
            ->where('studio_id', $person->studio_id)
            ->whereIn('id', $ids)
            ->where(function ($query) use ($previouslyAssigned): void {
                $query->where('active', true)
                    ->orWhereIn('id', $previouslyAssigned);
            })
            ->get();

        if ($tags->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'tag_ids' => 'One or more tags are unavailable in this studio.',
            ]);
        }

        $person->tagAssignments()->delete();

        foreach ($tags as $tag) {
            PersonTag::query()->create([
                'studio_id' => $person->studio_id,
                'person_id' => $person->getKey(),
                'tag_id' => $tag->getKey(),
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function syncInstruments(Person $person, array $attributes): void
    {
        if (! array_key_exists('instruments', $attributes)) {
            return;
        }

        $assignments = $attributes['instruments'] ?? [];
        $ids = array_values(array_unique(array_column($assignments, 'instrument_id')));
        $previouslyAssigned = $person->instrumentAssignments()
            ->whereIn('instrument_id', $ids)
            ->pluck('instrument_id');
        $instruments = Instrument::query()
            ->where('studio_id', $person->studio_id)
            ->whereIn('id', $ids)
            ->where(function ($query) use ($previouslyAssigned): void {
                $query->where('active', true)
                    ->orWhereIn('id', $previouslyAssigned);
            })
            ->get()
            ->keyBy(fn (Instrument $instrument): string => (string) $instrument->getKey());

        if ($instruments->count() !== count($ids) || count($ids) !== count($assignments)) {
            throw ValidationException::withMessages([
                'instruments' => 'Instrument assignments must be unique and belong to this studio.',
            ]);
        }

        $primaryCount = collect($assignments)->where('is_primary', true)->count();

        if ($primaryCount > 1) {
            throw ValidationException::withMessages([
                'instruments' => 'Only one instrument can be primary.',
            ]);
        }

        $person->instrumentAssignments()->delete();

        foreach ($assignments as $assignment) {
            PersonInstrument::query()->create([
                'studio_id' => $person->studio_id,
                'person_id' => $person->getKey(),
                'instrument_id' => $assignment['instrument_id'],
                'relationship' => $assignment['relationship'],
                'proficiency' => $assignment['proficiency'] ?? null,
                'is_primary' => $assignment['is_primary'] ?? false,
                'years_experience' => $assignment['years_experience'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function syncCustomFields(Person $person, array $attributes, bool $creating): void
    {
        if (! array_key_exists('custom_fields', $attributes) && ! $creating) {
            return;
        }

        $submitted = collect($attributes['custom_fields'] ?? [])->keyBy('definition_id');
        $definitions = CustomFieldDefinition::query()
            ->where('studio_id', $person->studio_id)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        $applicableDefinitions = $definitions->filter(
            fn (CustomFieldDefinition $definition): bool => $this->appliesTo($definition, $person),
        );

        foreach ($applicableDefinitions as $definition) {

            $field = $submitted->get($definition->getKey());

            if ($field === null) {
                if ($definition->required && ! $person->customFieldValues()
                    ->where('definition_id', $definition->getKey())->exists()) {
                    throw ValidationException::withMessages([
                        "custom_fields.{$definition->key}" => "{$definition->name} is required.",
                    ]);
                }

                continue;
            }

            $value = $field['value'] ?? null;
            $value = $this->validatedValue($definition, $value);

            if ($value === null || $value === '' || $value === []) {
                if ($definition->required) {
                    throw ValidationException::withMessages([
                        "custom_fields.{$definition->key}" => "{$definition->name} is required.",
                    ]);
                }

                CustomFieldValue::query()
                    ->where('studio_id', $person->studio_id)
                    ->where('definition_id', $definition->getKey())
                    ->where('person_id', $person->getKey())
                    ->delete();

                continue;
            }

            CustomFieldValue::query()->updateOrCreate(
                [
                    'studio_id' => $person->studio_id,
                    'definition_id' => $definition->getKey(),
                    'person_id' => $person->getKey(),
                ],
                ['value' => ['value' => $value]],
            );
        }

        $unknown = $submitted->keys()->diff($applicableDefinitions->modelKeys());

        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages([
                'custom_fields' => 'One or more custom fields are unavailable in this studio.',
            ]);
        }
    }

    private function appliesTo(CustomFieldDefinition $definition, Person $person): bool
    {
        return match ($definition->applies_to) {
            CustomFieldAppliesTo::Person => true,
            CustomFieldAppliesTo::Student => $person->studentProfile()->exists(),
            CustomFieldAppliesTo::Staff => $person->staffProfile()->exists(),
        };
    }

    private function validatedValue(CustomFieldDefinition $definition, mixed $value): mixed
    {
        $valid = match ($definition->type) {
            CustomFieldType::Text => is_string($value) && mb_strlen($value) <= 500,
            CustomFieldType::LongText => is_string($value) && mb_strlen($value) <= 10_000,
            CustomFieldType::Number => is_int($value) || is_float($value),
            CustomFieldType::Boolean => is_bool($value),
            CustomFieldType::Date => is_string($value) && $this->isDate($value),
            CustomFieldType::Select => is_string($value) && in_array($value, $definition->options ?? [], true),
            CustomFieldType::MultiSelect => is_array($value)
                && array_is_list($value)
                && count($value) === count(array_unique($value))
                && collect($value)->every(fn (mixed $item): bool => is_string($item)
                    && in_array($item, $definition->options ?? [], true)),
        };

        if (! $valid) {
            throw ValidationException::withMessages([
                "custom_fields.{$definition->key}" => "{$definition->name} has an invalid value.",
            ]);
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
