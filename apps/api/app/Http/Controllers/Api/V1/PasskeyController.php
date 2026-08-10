<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PasskeyController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $passkeys = $request->user()->passkeys()
            ->latest()
            ->get()
            ->map(fn ($passkey): array => [
                'id' => (string) $passkey->getKey(),
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
                'created_at' => $passkey->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(
            ['data' => $passkeys],
            options: JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }
}
