<?php

namespace App\SupportAccess;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class SupportAccessException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->codeName], $this->status);
    }
}
