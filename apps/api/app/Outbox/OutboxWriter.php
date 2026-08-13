<?php

namespace App\Outbox;

use App\Audit\CanonicalJson;
use App\Outbox\Jobs\ProcessOutboxMessage;
use App\Outbox\Models\OutboxAggregate;
use App\Outbox\Models\OutboxMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class OutboxWriter
{
    public function record(OutboxRecord $record): OutboxMessage
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Transactional outbox messages must be recorded inside a database transaction.');
        }

        $payloadJson = CanonicalJson::encode($record->payload);
        if (strlen($payloadJson) > 65536) {
            throw new LogicException('Outbox payloads may not exceed 64 KiB.');
        }
        $payloadHash = hash('sha256', $payloadJson);
        // Serializes the idempotency check across aggregates inside this studio,
        // so concurrent reuse becomes an exact replay or a typed conflict rather
        // than a driver-specific unique-constraint exception.
        if (DB::table('studios')->where('id', $record->studioId)->lockForUpdate()->first(['id']) === null) {
            throw new LogicException('The outbox studio does not exist.');
        }
        $existing = OutboxMessage::query()
            ->where('studio_id', $record->studioId)
            ->where('topic', $record->topic)
            ->where('idempotency_key', $record->idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $payloadHash)
                || $existing->aggregate_type !== $record->aggregateType
                || $existing->aggregate_id !== $record->aggregateId
                || $existing->schema_version !== $record->schemaVersion) {
                throw new OutboxIdempotencyConflict('The outbox idempotency key was reused with different content.');
            }

            return $existing;
        }

        OutboxAggregate::query()->insertOrIgnore([
            'studio_id' => $record->studioId,
            'aggregate_type' => $record->aggregateType,
            'aggregate_id' => $record->aggregateId,
            'last_sequence' => 0,
            'updated_at' => now(),
        ]);
        /** @var OutboxAggregate $aggregate */
        $aggregate = OutboxAggregate::query()
            ->where('studio_id', $record->studioId)
            ->where('aggregate_type', $record->aggregateType)
            ->where('aggregate_id', $record->aggregateId)
            ->lockForUpdate()
            ->firstOrFail();
        $sequence = $aggregate->last_sequence + 1;

        $message = OutboxMessage::query()->create([
            'studio_id' => $record->studioId,
            'topic' => $record->topic,
            'schema_version' => $record->schemaVersion,
            'aggregate_type' => $record->aggregateType,
            'aggregate_id' => $record->aggregateId,
            'aggregate_sequence' => $sequence,
            'idempotency_key' => $record->idempotencyKey,
            'correlation_id' => $record->correlationId ?? (string) Str::ulid(),
            'payload_ciphertext' => Crypt::encryptString($payloadJson),
            'payload_hash' => $payloadHash,
            'status' => 'pending',
            'available_at' => now(),
            'attempts' => 0,
            'max_attempts' => $record->maxAttempts,
        ]);
        OutboxAggregate::query()
            ->where('studio_id', $record->studioId)
            ->where('aggregate_type', $record->aggregateType)
            ->where('aggregate_id', $record->aggregateId)
            ->update(['last_sequence' => $sequence, 'updated_at' => now()]);

        ProcessOutboxMessage::dispatch($record->studioId, (string) $message->getKey())->afterCommit();

        return $message;
    }
}
