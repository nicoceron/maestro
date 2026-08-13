<?php

namespace App\Actions\People;

use App\Enums\PersonStatus;
use App\Models\GuardianRelationship;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateHousehold
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        Household $household,
        array $attributes,
        int $expectedVersion,
        User $actor,
    ): Household {
        Gate::forUser($actor)->authorize('update', $household);
        $studio = $this->tenantContext->studio();

        return DB::transaction(function () use (
            $household,
            $attributes,
            $expectedVersion,
            $actor,
            $studio,
        ): Household {
            $locked = Household::query()
                ->where('studio_id', $studio->getKey())
                ->lockForUpdate()
                ->findOrFail($household->getKey());

            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => 'This household changed after you opened it. Refresh and try again.',
                ]);
            }

            $locked->fill(Arr::only($attributes, ['name', 'notes']));

            if (array_key_exists('members', $attributes)) {
                $this->replaceMembers($locked, $attributes, $actor);
            }

            $locked->version++;
            $locked->save();

            return $locked->load([
                'members.person.studentProfile',
                'guardianRelationships',
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function replaceMembers(Household $household, array $attributes, User $actor): void
    {
        /** @var list<array<string, mixed>> $members */
        $members = $attributes['members'];
        $studioId = (string) $household->studio_id;
        $existingIds = collect($members)
            ->pluck('person_id')
            ->filter()
            ->values()
            ->all();
        $existingPeople = Person::query()
            ->where('studio_id', $studioId)
            ->whereIn('id', $existingIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (Person $person): string => (string) $person->getKey());

        if ($existingPeople->count() !== count($existingIds)) {
            throw ValidationException::withMessages([
                'members' => 'One or more people are unavailable in this studio.',
            ]);
        }

        $peopleByKey = [];
        $retainedPersonIds = [];

        foreach ($members as $index => $member) {
            $personId = $member['person_id'] ?? null;

            if (is_string($personId)) {
                /** @var Person $person */
                $person = $existingPeople->get($personId);

                if ($person->version !== (int) ($member['version'] ?? 0)) {
                    throw ValidationException::withMessages([
                        "members.{$index}.version" => 'This person changed after you opened the household.',
                    ]);
                }

                $person->fill(Arr::only($member, [
                    'first_name',
                    'last_name',
                    'preferred_name',
                    'email',
                    'phone',
                    'birth_date',
                    'pronouns',
                ]));
                $person->version++;
                $person->save();
                $this->syncStudent($person, $member['student'] ?? null, $actor, $index);
            } else {
                $person = Person::query()->create([
                    ...Arr::only($member, [
                        'first_name',
                        'last_name',
                        'preferred_name',
                        'email',
                        'phone',
                        'birth_date',
                        'pronouns',
                    ]),
                    'studio_id' => $studioId,
                    'status' => PersonStatus::Active,
                ]);
                $this->syncStudent($person, $member['student'] ?? null, $actor, $index);
            }

            HouseholdMember::query()->updateOrCreate(
                [
                    'studio_id' => $studioId,
                    'household_id' => $household->getKey(),
                    'person_id' => $person->getKey(),
                ],
                [
                    'role' => $member['household_role'],
                    'is_primary_contact' => $member['is_primary_contact'],
                    'receives_billing' => $member['receives_billing'],
                ],
            );

            $peopleByKey[(string) $member['key']] = $person;
            $retainedPersonIds[] = $person->getKey();
        }

        GuardianRelationship::query()
            ->where('studio_id', $studioId)
            ->where('household_id', $household->getKey())
            ->delete();
        HouseholdMember::query()
            ->where('studio_id', $studioId)
            ->where('household_id', $household->getKey())
            ->whereNotIn('person_id', $retainedPersonIds)
            ->delete();

        foreach ($attributes['relationships'] ?? [] as $relationship) {
            GuardianRelationship::query()->create([
                'studio_id' => $studioId,
                'household_id' => $household->getKey(),
                'guardian_person_id' => $peopleByKey[$relationship['guardian_key']]->getKey(),
                'student_person_id' => $peopleByKey[$relationship['student_key']]->getKey(),
                'relationship' => $relationship['relationship'],
                'is_legal_guardian' => $relationship['is_legal_guardian'],
                'is_emergency_contact' => $relationship['is_emergency_contact'],
                'is_authorized_pickup' => $relationship['is_authorized_pickup'],
                'portal_permissions' => $relationship['portal_permissions'],
            ]);
        }
    }

    /** @param array<string, mixed>|null $student */
    private function syncStudent(Person $person, ?array $student, User $actor, int $index): void
    {
        $profile = $person->studentProfile()->first();

        if ($student === null) {
            if ($profile !== null) {
                throw ValidationException::withMessages([
                    "members.{$index}.student" => 'A student profile cannot be removed. Move it to former instead.',
                ]);
            }

            return;
        }

        if ($profile !== null) {
            if (($student['status'] ?? $profile->status->value) !== $profile->status->value) {
                throw ValidationException::withMessages([
                    "members.{$index}.student.status" => 'Use the student status transition action to change lifecycle state.',
                ]);
            }

            $profile->fill(Arr::only($student, ['joined_on', 'school_grade']))->save();

            return;
        }

        $now = now();
        $profile = StudentProfile::query()->create([
            'studio_id' => $person->studio_id,
            'person_id' => $person->getKey(),
            'status' => $student['status'],
            'joined_on' => $student['joined_on'] ?? null,
            'school_grade' => $student['school_grade'] ?? null,
            'learning_preferences' => [],
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
    }
}
