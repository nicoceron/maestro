<?php

namespace App\Actions\People;

use App\Enums\StudentStatus;
use App\Models\Person;
use App\Models\StudentProfile;
use App\Models\StudentStatusTransition;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TransitionStudentStatus
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(
        StudentProfile $profile,
        StudentStatus $next,
        ?string $reason,
        int $expectedPersonVersion,
        User $actor,
    ): StudentProfile {
        Gate::forUser($actor)->authorize('update', $profile->person);
        $studio = $this->tenantContext->studio();

        return DB::transaction(function () use ($profile, $next, $reason, $expectedPersonVersion, $actor, $studio): StudentProfile {
            $person = Person::query()
                ->where('studio_id', $studio->getKey())
                ->lockForUpdate()
                ->findOrFail($profile->person_id);

            if ($person->version !== $expectedPersonVersion) {
                throw ValidationException::withMessages([
                    'version' => 'This person changed after you opened it. Refresh and try again.',
                ]);
            }

            $locked = StudentProfile::query()
                ->where('studio_id', $studio->getKey())
                ->lockForUpdate()
                ->findOrFail($profile->getKey());
            $previous = $locked->status;

            if (! $previous->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A student cannot move from {$previous->value} to {$next->value}.",
                ]);
            }

            $now = now();
            StudentStatusTransition::query()->create([
                'studio_id' => $studio->getKey(),
                'student_profile_id' => $locked->getKey(),
                'person_id' => $locked->person_id,
                'actor_id' => $actor->getAuthIdentifier(),
                'previous_status' => $previous,
                'new_status' => $next,
                'reason' => $reason,
                'occurred_at' => $now,
            ]);

            return $locked->refresh();
        });
    }
}
