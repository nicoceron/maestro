<?php

namespace App\Actions\People;

use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdatePerson
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SyncPersonProfiles $profiles,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Person $person, array $attributes, int $expectedVersion, User $actor): Person
    {
        Gate::forUser($actor)->authorize('update', $person);
        $studio = $this->tenantContext->studio();

        try {
            return DB::transaction(function () use ($person, $attributes, $expectedVersion, $studio, $actor): Person {
                $locked = Person::query()
                    ->where('studio_id', $studio->getKey())
                    ->lockForUpdate()
                    ->findOrFail($person->getKey());

                if ($locked->version !== $expectedVersion) {
                    throw ValidationException::withMessages([
                        'version' => 'This person changed after you opened it. Refresh and try again.',
                    ]);
                }

                $locked->fill(Arr::only($attributes, [
                    'first_name',
                    'last_name',
                    'preferred_name',
                    'email',
                    'phone',
                    'birth_date',
                    'pronouns',
                    'status',
                    'source',
                    'external_reference',
                    'preferred_locale',
                ]));
                $locked->version++;
                $locked->save();

                $this->profiles->handle($locked, $attributes, $actor);

                return $locked->load([
                    'studentProfile',
                    'staffProfile',
                    'instrumentAssignments.instrument',
                    'tagAssignments.tag',
                    'customFieldValues.definition',
                    'householdMemberships.household',
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isExternalReferenceCollision($exception)) {
                throw ValidationException::withMessages([
                    'external_reference' => 'The external reference is already in use in this studio.',
                ]);
            }

            throw $exception;
        }
    }

    private function isExternalReferenceCollision(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'people_studio_id_external_reference_unique')
            || str_contains($message, 'people.studio_id, people.external_reference');
    }
}
