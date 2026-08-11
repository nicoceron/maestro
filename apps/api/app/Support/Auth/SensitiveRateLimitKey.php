<?php

namespace App\Support\Auth;

final class SensitiveRateLimitKey
{
    public function for(string $scope, string|int ...$dimensions): string
    {
        return $scope.'|'.hash_hmac(
            'sha256',
            implode("\0", array_map(static fn (string|int $value): string => (string) $value, $dimensions)),
            (string) config('app.key'),
        );
    }
}
