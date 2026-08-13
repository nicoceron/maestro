<?php

namespace App\Outbox;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class OutboxRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $studioId,
        public string $topic,
        public string $aggregateType,
        public string $aggregateId,
        public string $idempotencyKey,
        public array $payload,
        public ?string $correlationId = null,
        public int $schemaVersion = 1,
        public int $maxAttempts = 8,
    ) {
        if ($this->topic === '' || strlen($this->topic) > 120
            || preg_match('/^[a-z][a-z0-9_.-]+$/', $this->topic) !== 1) {
            throw new InvalidArgumentException('Outbox topics must be lowercase dotted identifiers.');
        }
        if ($this->idempotencyKey === '' || strlen($this->idempotencyKey) > 160) {
            throw new InvalidArgumentException('An outbox idempotency key is required.');
        }
        if ($this->correlationId !== null && ! Str::isUlid($this->correlationId)) {
            throw new InvalidArgumentException('Outbox correlation IDs must be ULIDs.');
        }
        if ($this->schemaVersion < 1 || $this->schemaVersion > 65535 || $this->maxAttempts < 1 || $this->maxAttempts > 25) {
            throw new InvalidArgumentException('Outbox schema version or maximum attempts is invalid.');
        }
    }
}
