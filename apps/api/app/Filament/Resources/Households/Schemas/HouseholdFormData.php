<?php

namespace App\Filament\Resources\Households\Schemas;

use App\Enums\GuardianRelationshipType;
use App\Enums\HouseholdMemberRole;
use App\Enums\PortalPermission;
use App\Enums\StudentStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

final class HouseholdFormData
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function validated(array $data): array
    {
        $members = collect($data['members'] ?? [])
            ->map(function (mixed $member): mixed {
                if (! is_array($member)) {
                    return $member;
                }

                if (($member['household_role'] ?? null) !== HouseholdMemberRole::Learner->value) {
                    unset($member['student']);
                }

                return $member;
            })
            ->values()
            ->all();
        $data['members'] = $members;
        $data['relationships'] = array_values($data['relationships'] ?? []);

        $validator = Validator::make($data, self::rules());
        $validator->after(fn (LaravelValidator $validator) => self::validateAggregate($validator, $data));

        try {
            return $validator->validate();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [
                        "data.{$key}" => $messages,
                    ])
                    ->all(),
            );
        }
    }

    /** @return array<string, mixed> */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'version' => ['nullable', 'integer', 'min:1'],
            'members' => ['required', 'array', 'min:1', 'max:20'],
            'members.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'members.*.person_id' => ['nullable', 'string', 'max:26', 'distinct'],
            'members.*.version' => ['required_with:members.*.person_id', 'nullable', 'integer', 'min:1'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.last_name' => ['nullable', 'string', 'max:100'],
            'members.*.preferred_name' => ['nullable', 'string', 'max:100'],
            'members.*.email' => ['nullable', 'email:rfc', 'max:254'],
            'members.*.phone' => ['nullable', 'string', 'max:40'],
            'members.*.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'members.*.pronouns' => ['nullable', 'string', 'max:60'],
            'members.*.household_role' => ['required', Rule::enum(HouseholdMemberRole::class)],
            'members.*.is_primary_contact' => ['required', 'boolean'],
            'members.*.receives_billing' => ['required', 'boolean'],
            'members.*.student' => ['nullable', 'array'],
            'members.*.student.status' => ['required_with:members.*.student', Rule::enum(StudentStatus::class)],
            'members.*.student.joined_on' => ['nullable', 'date'],
            'members.*.student.school_grade' => ['nullable', 'string', 'max:60'],
            'relationships' => ['array', 'max:40'],
            'relationships.*.guardian_key' => ['required', 'string'],
            'relationships.*.student_key' => ['required', 'string'],
            'relationships.*.relationship' => ['required', Rule::enum(GuardianRelationshipType::class)],
            'relationships.*.is_legal_guardian' => ['required', 'boolean'],
            'relationships.*.is_emergency_contact' => ['required', 'boolean'],
            'relationships.*.is_authorized_pickup' => ['required', 'boolean'],
            'relationships.*.portal_permissions' => ['required', 'array'],
            'relationships.*.portal_permissions.*' => ['string', Rule::enum(PortalPermission::class), 'distinct'],
        ];
    }

    /** @param array<string, mixed> $data */
    private static function validateAggregate(LaravelValidator $validator, array $data): void
    {
        $members = $data['members'] ?? [];
        $primary = collect($members)->filter(
            fn (mixed $member): bool => is_array($member) && ($member['is_primary_contact'] ?? false) === true,
        );

        if ($primary->count() !== 1) {
            $validator->errors()->add('members', 'A household must have exactly one primary contact.');
        } elseif (blank($primary->first()['email'] ?? null) && blank($primary->first()['phone'] ?? null)) {
            $validator->errors()->add('members', 'The primary contact needs an email address or phone number.');
        }

        $billingContacts = collect($members)->filter(
            fn (mixed $member): bool => is_array($member) && ($member['receives_billing'] ?? false) === true,
        );

        if ($billingContacts->isEmpty()) {
            $validator->errors()->add('members', 'A household must have at least one billing contact.');
        }

        foreach ($members as $index => $member) {
            if (! is_array($member)) {
                continue;
            }

            $isLearner = ($member['household_role'] ?? null) === HouseholdMemberRole::Learner->value;

            if ($isLearner !== isset($member['student'])) {
                $validator->errors()->add(
                    "members.{$index}.student",
                    'Learner members require a student profile and only learners may have one.',
                );
            }

            if (($member['receives_billing'] ?? false) === true && blank($member['email'] ?? null)) {
                $validator->errors()->add(
                    "members.{$index}.email",
                    'A billing contact needs an email address.',
                );
            }
        }

        $membersByKey = Arr::keyBy($members, 'key');
        $seen = [];

        foreach ($data['relationships'] ?? [] as $index => $relationship) {
            if (! is_array($relationship)) {
                continue;
            }

            $guardianKey = (string) ($relationship['guardian_key'] ?? '');
            $studentKey = (string) ($relationship['student_key'] ?? '');

            if (($membersByKey[$guardianKey]['household_role'] ?? null) !== HouseholdMemberRole::Guardian->value) {
                $validator->errors()->add("relationships.{$index}.guardian_key", 'Choose a guardian in this household.');
            }

            if (($membersByKey[$studentKey]['household_role'] ?? null) !== HouseholdMemberRole::Learner->value) {
                $validator->errors()->add("relationships.{$index}.student_key", 'Choose a student in this household.');
            }

            if (in_array(PortalPermission::Billing->value, $relationship['portal_permissions'] ?? [], true)
                && ($membersByKey[$guardianKey]['receives_billing'] ?? false) !== true) {
                $validator->errors()->add(
                    "relationships.{$index}.portal_permissions",
                    'Billing portal access requires a billing contact.',
                );
            }

            $pair = $guardianKey.'|'.$studentKey;

            if ($guardianKey === $studentKey || isset($seen[$pair])) {
                $validator->errors()->add("relationships.{$index}.student_key", 'Each guardian and student pair must be unique.');
            }

            $seen[$pair] = true;
        }
    }
}
