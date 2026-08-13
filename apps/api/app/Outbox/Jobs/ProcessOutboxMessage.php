<?php

namespace App\Outbox\Jobs;

use App\Outbox\OutboxProcessor;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProcessOutboxMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $studioId, public string $messageId) {}

    public function handle(OutboxProcessor $processor): void
    {
        $processor->process($this->studioId, $this->messageId, gethostname().':'.getmypid());
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('outbox:'.$this->messageId))->expireAfter(120)->dontRelease()];
    }
}
