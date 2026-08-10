<?php

namespace App\Actions\People;

use App\Enums\PersonStatus;
use App\Enums\PortalPermission;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateHousehold
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Household
    {
        return DB::transaction(function () use ($attributes): Household {
            $studio = $this->tenantContext->studio();
            $household = Household::query()->create([
                'studio_id' => $studio->getKey(),
                'name' => $attributes['name'],
                'notes' => $attributes['notes'] ?? null,
            ]);
            $peopleByKey = [];

            foreach ($attributes['members'] as $member) {
                $person = Person::query()->create([
                    'studio_id' => $studio->getKey(),
                    'first_name' => $member['first_name'],
                    'last_name' => $member['last_name'] ?? null,
                    'preferred_name' => $member['preferred_name'] ?? null,
                    'email' => $member['email'] ?? null,
                    'phone' => $member['phone'] ?? null,
                    'birth_date' => $member['birth_date'] ?? null,
                    'pronouns' => $member['pronouns'] ?? null,
                    'status' => PersonStatus::Active,
                ]);

                HouseholdMember::query()->create([
                    'studio_id' => $studio->getKey(),
                    'household_id' => $household->getKey(),
                    'person_id' => $person->getKey(),
                    'role' => $member['household_role'],
                    'is_primary_contact' => $member['is_primary_contact'],
                    'receives_billing' => $member['receives_billing'],
                ]);

                if (isset($member['student'])) {
                    StudentProfile::query()->create([
                        'studio_id' => $studio->getKey(),
                        'person_id' => $person->getKey(),
                        'status' => $member['student']['status'],
                        'joined_on' => $member['student']['joined_on'] ?? null,
                        'school_grade' => $member['student']['school_grade'] ?? null,
                    ]);
                }

                $peopleByKey[$member['key']] = $person;
            }

            foreach (Arr::get($attributes, 'relationships', []) as $relationship) {
                GuardianRelationship::query()->create([
                    'studio_id' => $studio->getKey(),
                    'household_id' => $household->getKey(),
                    'guardian_person_id' => $peopleByKey[$relationship['guardian_key']]->getKey(),
                    'student_person_id' => $peopleByKey[$relationship['student_key']]->getKey(),
                    'relationship' => $relationship['relationship'],
                    'is_legal_guardian' => $relationship['is_legal_guardian'],
                    'is_emergency_contact' => $relationship['is_emergency_contact'],
                    'is_authorized_pickup' => $relationship['is_authorized_pickup'],
                    'portal_permissions' => $relationship['portal_permissions'] ?? PortalPermission::defaults(),
                ]);
            }

            return $household->load([
                'members.person.studentProfile',
                'guardianRelationships',
            ]);
        });
    }
}
