<?php

namespace App\Support\Scheduling;

final class ScheduleProjectionIntents
{
    /** @param array<string, mixed> $command @return list<array{type: string, mode: string}> */
    public static function forChange(string $commandType, array $command): array
    {
        $intents = [
            ['type' => 'billing_recalculation', 'mode' => 'project_only'],
            ['type' => 'payroll_recalculation', 'mode' => 'project_only'],
        ];

        if ($commandType === 'cancel' || array_key_exists('makeup_required', $command)) {
            $intents[] = ['type' => 'makeup_reconciliation', 'mode' => 'project_only'];
        }

        return $intents;
    }

    /** @param list<array{type: string, mode: string}> $intents @return list<string> */
    public static function effectLabels(array $intents): array
    {
        return array_map(
            fn (array $intent): string => str_replace('_recalculation', '', str_replace('_reconciliation', '', $intent['type'])).'_projection',
            $intents,
        );
    }
}
