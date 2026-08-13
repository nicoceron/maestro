<?php

namespace App\Outbox\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class OutboxMessagePublished
{
    use Dispatchable;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $messageId,
        public string $studioId,
        public string $topic,
        public int $schemaVersion,
        public string $aggregateType,
        public string $aggregateId,
        public int $aggregateSequence,
        public string $correlationId,
        public array $payload,
    ) {}
}
