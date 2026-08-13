<?php

namespace App\Support\Scheduling;

use App\Exceptions\SchedulingConflict;
use App\Models\ScheduleChangePreview;

final class SchedulePreviewEnvelope
{
    /** @param array<string, mixed> $aggregateVersions @param array<string, mixed> $command */
    public static function hash(
        string $studioId,
        int|string $actorId,
        string $commandType,
        string $scope,
        array $aggregateVersions,
        array $command,
    ): string {
        return ScheduleCommand::hash([
            'studio_id' => $studioId,
            'actor_id' => (string) $actorId,
            'command_type' => $commandType,
            'scope' => $scope,
            'aggregate_versions' => $aggregateVersions,
            'command' => $command,
        ]);
    }

    /** @param list<array<string, mixed>> $conflicts */
    public static function softWarningFingerprint(array $conflicts): string
    {
        $warnings = collect($conflicts)
            ->where('severity', 'soft')
            ->map(fn (array $warning): array => [
                'code' => (string) ($warning['code'] ?? ''),
                'resource_id' => (string) ($warning['resource_id'] ?? ''),
            ])
            ->sortBy(fn (array $warning): string => $warning['code'].'|'.$warning['resource_id'])
            ->values()
            ->all();

        return ScheduleCommand::hash($warnings);
    }

    public static function assertValid(ScheduleChangePreview $preview): void
    {
        $canonicalCommand = ScheduleCommand::canonical($preview->command);
        $expected = self::hash(
            (string) $preview->studio_id,
            $preview->actor_id,
            (string) $preview->command_type,
            $preview->scope->value,
            ScheduleCommand::canonical($preview->aggregate_versions),
            $canonicalCommand,
        );

        if ($canonicalCommand !== $preview->command || ! hash_equals((string) $preview->command_hash, $expected)
            || ! hash_equals(
                (string) $preview->soft_warning_fingerprint,
                self::softWarningFingerprint($preview->conflicts),
            )) {
            throw new SchedulingConflict('preview_envelope_mismatch', 'The server-stored preview envelope failed integrity validation.');
        }
    }
}
