<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class AttendanceConflict extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->codeName], 409);
    }
}
