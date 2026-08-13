<?php

namespace App\Support\Attendance;

use App\Exceptions\AttendanceConflict;
use App\Models\AttendanceDomainCommand;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AttendanceIdempotency
{
    public function key(?string $key): string
    {
        $normalized = trim((string) $key);
        if ($normalized === '' || strlen($normalized) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A non-empty Idempotency-Key header of at most 100 characters is required.',
            ]);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $command */
    public function hash(string $studioId, User $actor, string $operation, array $command): string
    {
        return hash('sha256', json_encode([
            'studio_id' => $studioId,
            'actor_id' => $actor->getAuthIdentifier(),
            'operation' => $operation,
            'command' => $this->canonicalize($command),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function replay(string $studioId, User $actor, string $key, string $operation, string $hash): ?AttendanceDomainCommand
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                "attendance:{$studioId}:{$actor->getAuthIdentifier()}:{$key}",
            ]);
        }

        $record = AttendanceDomainCommand::query()
            ->where('studio_id', $studioId)
            ->where('actor_id', $actor->getAuthIdentifier())
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($record !== null && ($record->operation !== $operation || ! hash_equals($record->command_hash, $hash))) {
            throw new AttendanceConflict('idempotency_key_reused', 'The Idempotency-Key was already used for another command.');
        }

        return $record;
    }

    /** @param array<string, mixed> $projection */
    public function store(string $studioId, User $actor, string $key, string $operation, string $hash, array $projection): AttendanceDomainCommand
    {
        return AttendanceDomainCommand::query()->create([
            'studio_id' => $studioId,
            'actor_id' => $actor->getAuthIdentifier(),
            'operation' => $operation,
            'idempotency_key' => $key,
            'command_hash' => $hash,
            'result_projection' => $projection,
            'created_at' => now(),
        ]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        $value = Arr::sortRecursive($value);

        return array_map($this->canonicalize(...), $value);
    }
}
