<?php

namespace App\SupportAccess;

enum SupportScope: string
{
    case AuditRead = 'audit.read';
    case ConfigurationRead = 'configuration.read';
    case PeopleRead = 'people.read';
    case ScheduleRead = 'schedule.read';
    case DiagnosticsRead = 'diagnostics.read';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
