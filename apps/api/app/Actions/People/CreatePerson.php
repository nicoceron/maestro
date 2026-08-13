<?php

namespace App\Actions\People;

use App\Enums\PersonStatus;
use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreatePerson
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SyncPersonProfiles $profiles,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(array $attributes, User $actor): Person
    {
        $studio = $this->tenantContext->studio();
        Gate::forUser($actor)->authorize('create', [Person::class, $studio]);

        try {
            return DB::transaction(function () use ($attributes, $studio, $actor): Person {
                $person = Person::query()->create([
                    ...Arr::only($attributes, [
                        'first_name',
                        'last_name',
                        'preferred_name',
                        'email',
                        'phone',
                        'birth_date',
                        'pronouns',
                        'source',
                        'external_reference',
                        'preferred_locale',
                    ]),
                    'studio_id' => $studio->getKey(),
                    'status' => $attributes['status'] ?? PersonStatus::Active,
                ]);

                $this->profiles->handle($person, $attributes, $actor, creating: true);

                return $person->load($this->relations());
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

    /** @return list<string> */
    private function relations(): array
    {
        return [
            'studentProfile',
            'staffProfile',
            'instrumentAssignments.instrument',
            'tagAssignments.tag',
            'customFieldValues.definition',
            'householdMemberships.household',
        ];
    }

    private function isExternalReferenceCollision(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'people_studio_id_external_reference_unique')
            || str_contains($message, 'people.studio_id, people.external_reference');
    }
}
