<?php

namespace App\Console\Commands;

use App\Actions\Scheduling\ManageSchedulingHold;
use App\Models\EventSeries;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExpireSchedulingHolds extends Command
{
    protected $signature = 'scheduling:expire-holds';

    protected $description = 'Release expired temporary scheduling holds.';

    public function handle(ManageSchedulingHold $holds, RequestDatabaseContext $context): int
    {
        Studio::query()->select('id')->eachById(function (Studio $studio) use ($holds, $context): void {
            try {
                DB::transaction(function () use ($studio, $holds, $context): void {
                    $context->activateStudioId((string) $studio->getKey());
                    EventSeries::query()->where('studio_id', $studio->getKey())->where('status', 'draft')
                        ->whereNotNull('hold_expires_at')->where('hold_expires_at', '<=', now())
                        ->orderBy('id')->eachById(fn (EventSeries $series) => $holds->expire($series), column: 'id');
                });
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Failed to expire holds for studio {$studio->getKey()}.");
            }
        }, column: 'id');

        return self::SUCCESS;
    }
}
