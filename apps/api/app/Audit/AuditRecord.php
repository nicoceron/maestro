<?php

namespace App\Audit;

use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class AuditRecord
{
    public function __construct(
        public string $studioId,
        public string $eventType,
        public string $subjectType,
        public ?string $subjectId,
        public SafeAuditPayload $payload,
        public ?User $actor = null,
        public string $actorType = 'user',
        public ?string $actorDisplay = null,
        public ?string $supportSessionId = null,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public int $payloadVersion = 1,
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
