<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\GuardianRelationshipType;
use App\Enums\HouseholdMemberRole;
use App\Enums\PortalPermission;
use App\Enums\StudentStatus;
use App\Models\Household;
use App\Models\Studio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        $studio = $this->route('studio');

        return $studio instanceof Studio
            && ($this->user()?->can('create', [Household::class, $studio]) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->householdRules(creating: true);
    }

    /** @return array<string, mixed> */
    protected function householdRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'members' => [$required, 'array', 'min:1', 'max:20'],
            'members.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'members.*.person_id' => $creating
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'ulid', 'distinct'],
            'members.*.version' => $creating
                ? ['prohibited']
                : ['required_with:members.*.person_id', 'integer', 'min:1'],
            'members.*.first_name' => ['required', 'string', 'max:100'],
            'members.*.last_name' => ['nullable', 'string', 'max:100'],
            'members.*.preferred_name' => ['nullable', 'string', 'max:100'],
            'members.*.email' => ['nullable', 'email:rfc', 'max:254'],
            'members.*.phone' => ['nullable', 'string', 'max:40'],
            'members.*.birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'members.*.pronouns' => ['nullable', 'string', 'max:60'],
            'members.*.household_role' => ['required', Rule::enum(HouseholdMemberRole::class)],
            'members.*.is_primary_contact' => ['required', 'boolean'],
            'members.*.receives_billing' => ['required', 'boolean'],
            'members.*.student' => ['nullable', 'array'],
            'members.*.student.status' => ['required_with:members.*.student', Rule::enum(StudentStatus::class)],
            'members.*.student.joined_on' => ['nullable', 'date_format:Y-m-d'],
            'members.*.student.school_grade' => ['nullable', 'string', 'max:60'],
            'relationships' => ['sometimes', 'array', 'max:40'],
            'relationships.*.guardian_key' => ['required', 'string'],
            'relationships.*.student_key' => ['required', 'string', 'different:relationships.*.guardian_key'],
            'relationships.*.relationship' => ['required', Rule::enum(GuardianRelationshipType::class)],
            'relationships.*.is_legal_guardian' => ['required', 'boolean'],
            'relationships.*.is_emergency_contact' => ['required', 'boolean'],
            'relationships.*.is_authorized_pickup' => ['required', 'boolean'],
            'relationships.*.portal_permissions' => ['required', 'array'],
            'relationships.*.portal_permissions.*' => ['string', Rule::enum(PortalPermission::class), 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateHousehold($validator, creating: true);
    }

    protected function validateHousehold(Validator $validator, bool $creating): void
    {
        $validator->after(function (Validator $validator) use ($creating): void {
            if (! $creating && ! $this->has('members')) {
                if ($this->has('relationships')) {
                    $validator->errors()->add(
                        'relationships',
                        'Relationships can only be replaced together with household members.',
                    );
                }

                return;
            }

            /** @var array<int, array<string, mixed>> $members */
            $members = $this->input('members', []);
            $primaryContacts = array_filter(
                $members,
                static fn (array $member): bool => ($member['is_primary_contact'] ?? false) === true,
            );

            if (count($primaryContacts) !== 1) {
                $validator->errors()->add('members', 'A household must have exactly one primary contact.');
            }

            $billingContacts = array_filter(
                $members,
                static fn (array $member): bool => ($member['receives_billing'] ?? false) === true,
            );

            if ($billingContacts === []) {
                $validator->errors()->add('members', 'A household must have at least one billing contact.');
            }

            foreach ($members as $index => $member) {
                if (($member['is_primary_contact'] ?? false) === true
                    && blank($member['email'] ?? null)
                    && blank($member['phone'] ?? null)) {
                    $validator->errors()->add(
                        "members.{$index}.email",
                        'The primary contact needs an email address or phone number.',
                    );
                }

                if (($member['receives_billing'] ?? false) === true
                    && blank($member['email'] ?? null)) {
                    $validator->errors()->add(
                        "members.{$index}.email",
                        'A billing contact needs an email address.',
                    );
                }

                $isLearner = ($member['household_role'] ?? null) === HouseholdMemberRole::Learner->value;

                if ($isLearner !== isset($member['student'])) {
                    $validator->errors()->add(
                        "members.{$index}.student",
                        'Learner members require a student profile and only learners may have one.',
                    );
                }
            }

            $membersByKey = Arr::keyBy($members, 'key');
            $seenPairs = [];

            /** @var array<int, array<string, mixed>> $relationships */
            $relationships = $this->input('relationships', []);

            foreach ($relationships as $index => $relationship) {
                $guardianKey = (string) ($relationship['guardian_key'] ?? '');
                $studentKey = (string) ($relationship['student_key'] ?? '');
                $guardian = $membersByKey[$guardianKey] ?? null;
                $student = $membersByKey[$studentKey] ?? null;

                if (($guardian['household_role'] ?? null) !== HouseholdMemberRole::Guardian->value) {
                    $validator->errors()->add(
                        "relationships.{$index}.guardian_key",
                        'The guardian key must reference a guardian member.',
                    );
                }

                if (($student['household_role'] ?? null) !== HouseholdMemberRole::Learner->value) {
                    $validator->errors()->add(
                        "relationships.{$index}.student_key",
                        'The student key must reference a learner member.',
                    );
                }

                if (in_array(PortalPermission::Billing->value, $relationship['portal_permissions'] ?? [], true)
                    && ($guardian['receives_billing'] ?? false) !== true) {
                    $validator->errors()->add(
                        "relationships.{$index}.portal_permissions",
                        'Billing portal access requires a billing contact.',
                    );
                }

                $pair = $guardianKey.'|'.$studentKey;

                if (isset($seenPairs[$pair])) {
                    $validator->errors()->add(
                        "relationships.{$index}.student_key",
                        'Each guardian and learner relationship may only be declared once.',
                    );
                }

                $seenPairs[$pair] = true;
            }
        });
    }
}
