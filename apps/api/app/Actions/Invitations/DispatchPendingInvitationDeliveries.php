<?php

namespace App\Actions\Invitations;

use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Support\Facades\DB;

final class DispatchPendingInvitationDeliveries
{
    public function __construct(
        private readonly RequestDatabaseContext $databaseContext,
        private readonly InvitationDeliveryOutbox $outbox,
    ) {}

    public function handle(): int
    {
        $dispatched = 0;

        Studio::query()->orderBy('id')->eachById(function (Studio $studio) use (&$dispatched): void {
            $invitations = DB::transaction(function () use ($studio) {
                $this->databaseContext->activateStudioId((string) $studio->getKey());

                $ids = StudioInvitationDelivery::query()
                    ->where('studio_id', $studio->getKey())
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->pluck('invitation_id');

                return StudioInvitation::query()
                    ->whereIn('id', $ids)
                    ->get();
            });

            foreach ($invitations as $invitation) {
                $this->outbox->dispatch($invitation);
                $dispatched++;
            }
        }, column: 'id');

        return $dispatched;
    }
}
