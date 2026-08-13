<?php

namespace App\Console\Commands;

use App\Jobs\ExtendEventSeriesHorizon;
use App\Models\EventSeries;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExtendSchedulingHorizon extends Command
{
    protected $signature = 'scheduling:extend-horizon {--days=548}';

    protected $description = 'Queue bounded event-series materialization chunks.';

    public function handle(RequestDatabaseContext $context): int
    {
        $target = now()->addDays(max(1, min(548, (int) $this->option('days'))));

        Studio::query()->select('id')->eachById(function (Studio $studio) use ($context, $target): void {
            try {
                DB::transaction(function () use ($studio, $context, $target): void {
                    $context->activateStudioId((string) $studio->getKey());
                    EventSeries::query()->whereIn('status', ['draft', 'active', 'paused'])
                        ->where(fn ($query) => $query->whereNull('materialized_through')->orWhere('materialized_through', '<', $target))
                        ->orderBy('id')->eachById(fn (EventSeries $series) => ExtendEventSeriesHorizon::dispatch(
                            $studio->getKey(), $series->getKey(), $series->version,
                        ), column: 'id');
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Failed to queue horizon work for studio {$studio->getKey()}.");
            }
        }, column: 'id');

        return self::SUCCESS;
    }
}
