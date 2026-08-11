<?php

namespace App\Console\Commands;

use App\Actions\Invitations\DispatchPendingInvitationDeliveries as DispatchPendingInvitationDeliveriesAction;
use Illuminate\Console\Command;

final class DispatchPendingInvitationDeliveries extends Command
{
    protected $signature = 'invitations:dispatch-pending';

    protected $description = 'Dispatch recoverable pending invitation delivery intents';

    public function handle(DispatchPendingInvitationDeliveriesAction $action): int
    {
        $count = $action->handle();
        $this->info("Dispatched {$count} pending invitation delivery intents.");

        return self::SUCCESS;
    }
}
