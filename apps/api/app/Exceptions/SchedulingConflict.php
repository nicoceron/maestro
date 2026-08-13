<?php

namespace App\Exceptions;

use RuntimeException;

final class SchedulingConflict extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}
