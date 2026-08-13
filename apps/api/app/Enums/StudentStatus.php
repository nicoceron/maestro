<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Lead = 'lead';
    case Trial = 'trial';
    case Waiting = 'waiting';
    case Active = 'active';
    case Paused = 'paused';
    case Former = 'former';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Lead => [self::Trial, self::Waiting, self::Active, self::Former],
            self::Trial => [self::Lead, self::Waiting, self::Active, self::Former],
            self::Waiting => [self::Lead, self::Trial, self::Active, self::Former],
            self::Active => [self::Paused, self::Former],
            self::Paused => [self::Active, self::Former],
            self::Former => [self::Lead, self::Trial, self::Waiting, self::Active],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
