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
use Illuminate\Validation\ValidationException;

final class UpdateSchedulingRecord
{
    public function __construct(
        private readonly SchedulingRecordRegistry $registry,
        private readonly SchedulingAccess $access,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        string $type,
        Studio $studio,
        Model $record,
        array $attributes,
        int $version,
        User $actor,
        bool $approvalTransition = false,
    ): Model {
        Gate::forUser($actor)->authorize('update', $record);

        if ($type === 'availability-overrides') {
            $canManage = $this->access->canManage($actor, $studio);

            if (! $approvalTransition && array_intersect(
                array_keys($attributes),
                ['approval_status', 'enforcement'],
            ) !== []) {
                throw ValidationException::withMessages([
                    'approval_status' => 'Use the availability approval transition to change approval state.',
                    'enforcement' => 'Use the availability approval transition to change enforcement.',
                ]);
            }

            if ($approvalTransition && ! $canManage) {
                throw ValidationException::withMessages([
                    'approval_status' => 'Only scheduling managers may approve or decline overrides.',
                ]);
            }

            if (array_intersect(array_keys($attributes), [
                'kind', 'starts_at', 'ends_at', 'timezone', 'reason', 'active',
            ]) !== []) {
                // Any material edit invalidates a previous approval. The dedicated
                // approval transition is the only path that can restore it.
                $attributes['approval_status'] = 'pending';
                $attributes['enforcement'] = 'hard';
            } elseif (! $approvalTransition) {
                unset($attributes['enforcement']);
            }
        }

        $attributes = $this->registry->prepare($type, $attributes);

        if (isset($attributes['staff_profile_id'])
            && ! $this->access->canMutateStaffProfile($actor, $studio, $attributes['staff_profile_id'])) {
            abort(403);
        }

        try {
            return DB::transaction(function () use ($type, $studio, $record, $attributes, $version): Model {
                /** @var Model $locked */
                $locked = $record::query()
                    ->where('studio_id', $studio->getKey())
                    ->lockForUpdate()
                    ->findOrFail($record->getKey());

                if ((int) $locked->version !== $version) {
                    throw ValidationException::withMessages([
                        'version' => 'This scheduling record changed after you opened it. Refresh and try again.',
                    ]);
                }

                $this->registry->lockDomain($type, $studio, $attributes, $locked);
                $this->registry->assertDomain($type, $studio, $attributes, $locked);
                $locked->fill($this->registry->attributesFor($type, $attributes));
                $locked->version++;
                $locked->save();

                return $locked->load($this->registry->relations($type));
            });
        } catch (QueryException $exception) {
            throw $this->registry->validationException($exception) ?? $exception;
        }
    }
}
