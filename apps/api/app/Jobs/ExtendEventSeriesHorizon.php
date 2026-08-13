<?php

namespace App\Jobs;

use App\Actions\Scheduling\MaterializeEventSeries;
use App\Models\EventSeries;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ExtendEventSeriesHorizon implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $studioId,
        public readonly string $eventSeriesId,
        public readonly int $expectedVersion,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->studioId.':'.$this->eventSeriesId.':'.$this->expectedVersion;
    }

    public function handle(MaterializeEventSeries $materialize, RequestDatabaseContext $context): void
    {
        $studio = Studio::query()->findOrFail($this->studioId);
        DB::transaction(function () use ($studio, $materialize, $context): void {
            $context->activateStudioId((string) $studio->getKey());
            $series = EventSeries::query()->where('studio_id', $studio->getKey())->findOrFail($this->eventSeriesId);

            if ($series->version !== $this->expectedVersion || in_array($series->status->value, ['ended', 'canceled'], true)) {
                return;
            }

            $from = $series->materialized_through?->subDay() ?? CarbonImmutable::now()->subDays(30);
            $materialize->handle($series, $from, $from->addDays(90));
        });
    }
}
