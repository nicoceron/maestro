<?php

namespace App\DataPortability\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class CrmDataPortabilityConflict extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->messageForCode(), 'code' => $this->getMessage()], 409);
    }

    private function messageForCode(): string
    {
        return match ($this->getMessage()) {
            'IDEMPOTENCY_KEY_REUSED' => 'The idempotency key was already used with different content.',
            'CRM_IMPORT_VERSION_CONFLICT','CRM_IMPORT_PLAN_VERSION_CONFLICT' => 'The import plan changed. Refresh and try again.',
            'CRM_IMPORT_CANDIDATE_STALE' => 'The selected match changed. Refresh the preview.',
            'CRM_PORTABLE_TARGET_NOT_EMPTY' => 'Portable bundle import requires an empty CRM target.',
            default => 'The CRM data portability operation conflicts with its current state.',
        };
    }
}
