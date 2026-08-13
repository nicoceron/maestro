<?php

namespace App\DataLifecycle\Support;

use App\DataLifecycle\Models\TenantDataLifecycleEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantDataLifecycleAudit
{
    /** @param array<string, mixed> $metadata */
    public function record(
        Model $aggregate,
        string $aggregateType,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?User $actor,
        array $metadata = [],
    ): TenantDataLifecycleEvent {
        [$requestId, $ipHash] = $this->requestMetadata();

        $sequence = (int) DB::table('tenant_data_lifecycle_events')
            ->where('studio_id', $aggregate->getAttribute('studio_id'))
            ->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregate->getKey())
            ->max('sequence') + 1;

        return TenantDataLifecycleEvent::query()->create([
            'studio_id' => $aggregate->getAttribute('studio_id'),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregate->getKey(),
            'sequence' => $sequence,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->getAuthIdentifier(),
            'request_id' => $requestId,
            'request_ip_hash' => $ipHash,
            'metadata' => $metadata === [] ? (object) [] : $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{?string, ?string} */
    private function requestMetadata(): array
    {
        $request = app()->bound('request') ? request() : null;

        if (! $request instanceof Request) {
            return [null, null];
        }

        $requestId = $request->attributes->get('_maestro_tenant_lifecycle_request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::ulid();
            $request->attributes->set('_maestro_tenant_lifecycle_request_id', $requestId);
        }

        return [
            $requestId,
            $request->ip() === null
                ? null
                : hash_hmac('sha256', $request->ip(), (string) config('app.key')),
        ];
    }
}
