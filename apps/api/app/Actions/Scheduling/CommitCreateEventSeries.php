<?php

namespace App\Actions\Scheduling;

use App\Enums\SchedulePreviewStatus;
use App\Exceptions\SchedulingConflict;
use App\Models\EventSeries;
use App\Models\ScheduleChangeEvent;
use App\Models\ScheduleChangePreview;
use App\Models\SchedulingOutboxMessage;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use App\Support\Scheduling\ScheduleProjectionIntents;
use App\Support\Scheduling\SchedulingIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CommitCreateEventSeries
{
    public function __construct(
        private readonly CreateEventSeries $create,
        private readonly PreviewCreateEventSeries $previewAction,
        private readonly SchedulingIdempotency $idempotency,
    ) {}

    public function handle(Studio $studio, ScheduleChangePreview $preview, string $idempotencyKey, User $actor): EventSeries
    {
        Gate::forUser($actor)->authorize('create', [EventSeries::class, $studio]);

        if ($preview->studio_id !== $studio->getKey() || $preview->actor_id !== $actor->getAuthIdentifier()
            || ! in_array($preview->command_type, ['create_event_series', 'clone_event_series'], true)) {
            abort(404);
        }

        return DB::transaction(function () use ($studio, $preview, $idempotencyKey, $actor): EventSeries {
            $operationHash = $this->idempotency->operationHash(
                'schedule.create', $preview->getKey().'|'.$preview->command_hash,
            );
            $claim = $this->idempotency->claim((string) $studio->getKey(), $actor, $idempotencyKey, $operationHash);

            if ($claim->isCompleted()) {
                return $this->idempotency->replay($claim, EventSeries::class);
            }

            $preview = ScheduleChangePreview::query()->lockForUpdate()->findOrFail($preview->getKey());
            $command = $preview->command;
            SchedulePreviewEnvelope::assertValid($preview);

            if ($preview->consumed_at !== null) {
                throw new SchedulingConflict('preview_consumed', 'This schedule preview was already consumed.');
            }

            if ($preview->expires_at->isPast()) {
                throw new SchedulingConflict('preview_expired', 'This schedule preview expired. Generate a new preview.');
            }

            if ($preview->status === SchedulePreviewStatus::Blocked || collect($preview->conflicts)->contains('severity', 'hard')) {
                throw new SchedulingConflict('hard_scheduling_conflict', 'Hard scheduling conflicts cannot be overridden.');
            }

            if (collect($preview->conflicts)->contains('severity', 'soft') && ! $preview->soft_warnings_acknowledged) {
                throw new SchedulingConflict('soft_warning_unacknowledged', 'Soft warnings must be acknowledged by a scheduling manager.');
            }

            if ($preview->aggregate_versions !== ScheduleCommand::canonical($this->previewAction->versions($studio->getKey(), $command))) {
                throw new SchedulingConflict('schedule_changed_after_preview', 'A referenced scheduling resource changed after preview.');
            }

            $fresh = $this->previewAction->handle($studio, $command, false, $actor, $preview->command_type);

            if ($fresh->status === SchedulePreviewStatus::Blocked) {
                $fresh->delete();
                throw new SchedulingConflict('hard_scheduling_conflict', 'The schedule developed a hard conflict after preview.');
            }

            if (! hash_equals($preview->soft_warning_fingerprint, $fresh->soft_warning_fingerprint)) {
                $fresh->delete();
                throw new SchedulingConflict('soft_warnings_changed', 'Soft scheduling warnings changed after preview. Generate and acknowledge a new preview.');
            }

            $series = $this->create->handle($studio, $command, $actor);
            $preview->consumed_at = now();
            $preview->save();
            $fresh->delete();
            ScheduleChangeEvent::query()->create([
                'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
                'event_type' => 'schedule.series_created', 'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'command_hash' => $preview->command_hash,
                    'preview_id' => $preview->getKey(),
                    'projection_intents' => ScheduleProjectionIntents::forChange($preview->command_type, $command),
                ],
                'occurred_at' => now(),
            ]);
            SchedulingOutboxMessage::query()->create([
                'studio_id' => $studio->getKey(), 'topic' => 'schedule.created', 'aggregate_type' => 'event_series',
                'aggregate_id' => $series->getKey(), 'aggregate_version' => $series->version,
                'dedupe_key' => 'series-created:'.$preview->getKey(),
                'payload' => [
                    'event_series_id' => $series->getKey(),
                    'projection_intents' => ScheduleProjectionIntents::forChange($preview->command_type, $command),
                ],
                'available_at' => now(),
            ]);
            $this->idempotency->complete($claim, $series);

            return $series;
        }, 3);
    }
}
