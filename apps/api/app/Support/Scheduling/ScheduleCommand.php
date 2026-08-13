<?php

namespace App\Support\Scheduling;

final class ScheduleCommand
{
    /** @param array<string, mixed> $command */
    public static function canonical(array $command): array
    {
        foreach ($command as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }

        unset($value);

        if (! array_is_list($command)) {
            ksort($command);
        }

        return $command;
    }

    /** @param array<string, mixed> $command */
    public static function hash(array $command): string
    {
        return hash('sha256', json_encode(self::canonical($command), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
