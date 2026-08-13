<?php

namespace App\Outbox;

use App\Audit\StudioDatabaseScope;
use App\Outbox\Events\OutboxMessagePublished;
use App\Outbox\Jobs\ProcessOutboxMessage;
use App\Outbox\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OutboxProcessor
{
    public function __construct(private readonly StudioDatabaseScope $studioScope) {}

    public function process(string $studioId, string $messageId, string $workerId): bool
    {
        return $this->studioScope->run($studioId, function () use ($messageId, $workerId): bool {
            $claimed = $this->claim($messageId, $workerId);
            if ($claimed === null) {
                return false;
            }

            try {
                OutboxMessagePublished::dispatch(
                    (string) $claimed->getKey(),
                    $claimed->studio_id,
                    $claimed->topic,
                    $claimed->schema_version,
                    $claimed->aggregate_type,
                    $claimed->aggregate_id,
                    $claimed->aggregate_sequence,
                    $claimed->correlation_id,
                    $claimed->payload(),
                );
                $this->markProcessed($claimed);
            } catch (Throwable $exception) {
                $this->markFailed($claimed, $exception);
            }

            return true;
        });
    }

    /** @return list<array{studio_id: string, id: string}> */
    public function due(int $limit = 100): array
    {
        $remaining = max(1, min(1000, $limit));
        $due = [];

        foreach (DB::table('studios')->orderBy('id')->pluck('id') as $studioId) {
            if ($remaining <= 0) {
                break;
            }
            $ids = $this->studioScope->run((string) $studioId, fn () => OutboxMessage::query()
                ->where(function ($query): void {
                    $query->whereIn('status', ['pending', 'retry'])->where('available_at', '<=', now())
                        ->orWhere(function ($query): void {
                            $query->where('status', 'processing')->where('claim_expires_at', '<=', now());
                        });
                })->orderBy('available_at')->limit($remaining)->pluck('id')->all());
            foreach ($ids as $id) {
                $due[] = ['studio_id' => (string) $studioId, 'id' => (string) $id];
                $remaining--;
            }
        }

        return $due;
    }

    private function claim(string $messageId, string $workerId): ?OutboxMessage
    {
        return DB::transaction(function () use ($messageId, $workerId): ?OutboxMessage {
            /** @var OutboxMessage|null $message */
            $message = OutboxMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($message === null || $message->status === 'processed' || $message->status === 'dead_letter') {
                return null;
            }
            $claimable = in_array($message->status, ['pending', 'retry'], true) && $message->available_at->lte(now())
                || $message->status === 'processing' && $message->claim_expires_at?->lte(now());
            if (! $claimable || $message->attempts >= $message->max_attempts) {
                return null;
            }

            $message->forceFill([
                'status' => 'processing',
                'claimed_at' => now(),
                'claimed_by' => mb_substr($workerId, 0, 100),
                'claim_token' => (string) Str::ulid(),
                'claim_expires_at' => now()->addMinutes(2),
                'attempts' => $message->attempts + 1,
                'last_error_code' => null,
                'last_error_summary' => null,
            ])->save();

            return $message->refresh();
        }, 5);
    }

    private function markProcessed(OutboxMessage $message): void
    {
        OutboxMessage::query()->whereKey($message->getKey())
            ->where('status', 'processing')->where('claim_token', $message->claim_token)
            ->update([
                'status' => 'processed', 'processed_at' => now(), 'published_at' => now(),
                'claimed_at' => null, 'claimed_by' => null, 'claim_token' => null,
                'claim_expires_at' => null, 'updated_at' => now(),
            ]);
    }

    private function markFailed(OutboxMessage $message, Throwable $exception): void
    {
        $dead = $message->attempts >= $message->max_attempts;
        $delay = min(3600, 15 * (2 ** min(8, max(0, $message->attempts - 1))));
        $updated = OutboxMessage::query()->whereKey($message->getKey())
            ->where('status', 'processing')->where('claim_token', $message->claim_token)
            ->update([
                'status' => $dead ? 'dead_letter' : 'retry',
                'available_at' => $dead ? $message->available_at : now()->addSeconds($delay),
                'claimed_at' => null, 'claimed_by' => null, 'claim_token' => null,
                'claim_expires_at' => null, 'last_error_code' => 'handler_exception',
                'last_error_summary' => mb_substr(class_basename($exception), 0, 160),
                'dead_lettered_at' => $dead ? now() : null, 'updated_at' => now(),
            ]);

        if ($updated === 1 && ! $dead) {
            ProcessOutboxMessage::dispatch($message->studio_id, (string) $message->getKey())
                ->delay($delay)->afterCommit();
        }
    }
}
