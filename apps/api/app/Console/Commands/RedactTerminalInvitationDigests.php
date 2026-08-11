<?php

namespace App\Console\Commands;

use App\Actions\Invitations\RedactTerminalInvitationDigests as RedactTerminalInvitationDigestsAction;
use Illuminate\Console\Command;

final class RedactTerminalInvitationDigests extends Command
{
    protected $signature = 'invitations:redact-terminal-digests';

    protected $description = 'Redact invitation token digests 30 days after terminal state';

    public function handle(RedactTerminalInvitationDigestsAction $action): int
    {
        $count = $action->handle();
        $this->info("Redacted {$count} terminal invitation token digests.");

        return self::SUCCESS;
    }
}
