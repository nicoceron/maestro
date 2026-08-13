<?php

namespace App\Support\Scheduling;

use App\Exceptions\SchedulingConflict;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\EventSeriesEquipment;
use App\Models\EventSeriesRoom;
use App\Models\EventSeriesTeacher;
use App\Models\SchedulingCommandClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SchedulingIdempotency
{
    public function claim(string $studioId, User $actor, string $key, string $operationHash): SchedulingCommandClaim
    {
        $key = trim($key);

        if ($key === '' || strlen($key) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key of at most 100 characters is required.']);
        }

        $now = now();
        DB::table('scheduling_command_claims')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'studio_id' => $studioId,
            'actor_id' => $actor->getAuthIdentifier(),
            'idempotency_key' => $key,
            'operation_hash' => $operationHash,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $claim = SchedulingCommandClaim::query()
            ->where('studio_id', $studioId)
            ->where('actor_id', $actor->getAuthIdentifier())
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->firstOrFail();

        if (! hash_equals($claim->operation_hash, $operationHash)) {
            throw new SchedulingConflict('idempotency_key_reused', 'This Idempotency-Key was already claimed by a different scheduling command.');
        }

        return $claim;
    }

    public function operationHash(string $operation, string $binding): string
    {
        return ScheduleCommand::hash(['operation' => $operation, 'binding' => $binding]);
    }

    public function complete(SchedulingCommandClaim $claim, Model $result): void
    {
        $projection = ['attributes' => $result->getAttributes(), 'relations' => []];

        if ($result instanceof EventSeries) {
            $occurrencesWereLoaded = $result->relationLoaded('occurrences');
            $result->loadMissing(['teachers', 'rooms', 'equipmentRequirements']);
            $projection['relations'] = [
                'teachers' => $result->teachers->map->getAttributes()->all(),
                'rooms' => $result->rooms->map->getAttributes()->all(),
                'equipmentRequirements' => $result->equipmentRequirements->map->getAttributes()->all(),
            ];

            if ($occurrencesWereLoaded) {
                $projection['relations']['occurrences'] = $result->occurrences->map->getAttributes()->all();
            }
        }

        $claim->forceFill([
            'status' => 'completed',
            'result_type' => $result::class,
            'result_id' => (string) $result->getKey(),
            'result_projection' => $projection,
            'completed_at' => now(),
        ])->save();
    }

    /** @template T of Model @param class-string<T> $expectedClass @return T */
    public function replay(SchedulingCommandClaim $claim, string $expectedClass): Model
    {
        if (! $claim->isCompleted() || $claim->result_type !== $expectedClass || ! is_array($claim->result_projection)) {
            throw new SchedulingConflict('idempotency_claim_incomplete', 'The scheduling command is still being completed. Retry the same request.');
        }

        $projection = $claim->result_projection;
        $attributes = $projection['attributes'] ?? $projection;
        /** @var T $model */
        $model = (new $expectedClass)->newFromBuilder($attributes);

        if ($model instanceof EventSeries) {
            $relations = $projection['relations'] ?? [];
            foreach ([
                'teachers' => EventSeriesTeacher::class,
                'rooms' => EventSeriesRoom::class,
                'equipmentRequirements' => EventSeriesEquipment::class,
                'occurrences' => EventOccurrence::class,
            ] as $relation => $class) {
                if (array_key_exists($relation, $relations)) {
                    $model->setRelation($relation, collect($relations[$relation])->map(
                        fn (array $attributes): Model => (new $class)->newFromBuilder($attributes),
                    ));
                }
            }
        }

        return $model;
    }
}
