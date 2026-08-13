<?php

namespace App\DataPortability\Actions;

use App\Actions\People\UpdateHousehold;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final readonly class ApplyImportedHouseholdMembership
{
    public function __construct(private UpdateHousehold $updateHousehold) {}

    /** @param array<string,string> $payload */
    public function handle(Studio $studio, User $actor, Person $person, array $payload, ?Household $household, ?int $expectedVersion): ?Household
    {
        if ($payload['household_name'] === '' && $household === null) {
            return null;
        }

        if ($household === null) {
            Gate::forUser($actor)->authorize('create', [Household::class, $studio]);
            $household = Household::query()->create([
                'studio_id' => $studio->getKey(), 'name' => $payload['household_name'],
                'notes' => $payload['household_notes'] ?: null,
            ]);
        } else {
            $household = $this->updateHousehold->handle($household, [
                'name' => $payload['household_name'] ?: $household->name,
                'notes' => $payload['household_notes'] ?: $household->notes,
            ], (int) $expectedVersion, $actor);
        }
        HouseholdMember::query()->updateOrCreate([
            'studio_id' => $studio->getKey(), 'household_id' => $household->getKey(), 'person_id' => $person->getKey(),
        ], [
            'role' => $payload['household_role'] ?: 'other',
            'is_primary_contact' => filter_var($payload['is_primary_contact'], FILTER_VALIDATE_BOOL),
            'receives_billing' => filter_var($payload['receives_billing'], FILTER_VALIDATE_BOOL),
        ]);

        return $household;
    }
}
