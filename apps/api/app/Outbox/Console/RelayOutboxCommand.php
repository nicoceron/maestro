<?php

namespace App\Outbox\Console;

use App\Outbox\Jobs\ProcessOutboxMessage;
use App\Outbox\OutboxProcessor;
use Illuminate\Console\Command;

final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay {--limit=100 : Maximum messages to enqueue}';

    protected $description = 'Recover due, retried, and expired-lease transactional outbox messages';

    public function handle(OutboxProcessor $processor): int
    {
        $due = $processor->due((int) $this->option('limit'));
        foreach ($due as $message) {
            ProcessOutboxMessage::dispatch($message['studio_id'], $message['id']);
        }
        $this->info(count($due).' outbox message(s) enqueued.');

        return self::SUCCESS;
    }
}
