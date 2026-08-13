<?php

namespace App\Actions\Scheduling;

use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\SchedulingAccess;
use App\Support\Scheduling\SchedulingRecordRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CreateSchedulingRecord
{
    public function __construct(
        private readonly SchedulingRecordRegistry $registry,
        private readonly SchedulingAccess $access,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(string $type, Studio $studio, array $attributes, User $actor): Model
    {
        $class = $this->registry->modelClass($type);
        Gate::forUser($actor)->authorize('create', [$class, $studio]);

        if ($type === 'availability-overrides') {
            $attributes['approval_status'] = 'pending';
            $attributes['enforcement'] = 'hard';
        }

        $attributes = $this->registry->prepare($type, $attributes);
        if (isset($attributes['staff_profile_id'])
            && ! $this->access->canMutateStaffProfile($actor, $studio, $attributes['staff_profile_id'])) {
            abort(403);
        }

        try {
            return DB::transaction(function () use ($class, $studio, $attributes, $type): Model {
                $this->registry->lockDomain($type, $studio, $attributes);
                $this->registry->assertDomain($type, $studio, $attributes);

                /** @var Model $record */
                $record = $class::query()->create([
                    ...$this->registry->attributesFor($type, $attributes),
                    'studio_id' => $studio->getKey(),
                ]);

                return $record->load($this->registry->relations($type));
            });
        } catch (QueryException $exception) {
            throw $this->registry->validationException($exception) ?? $exception;
        }
    }
}
