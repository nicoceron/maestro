<?php

namespace App\Support\Auth;

use Illuminate\Contracts\Cache\Repository;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

final class SecureTwoFactorAuthenticationProvider implements TwoFactorAuthenticationProvider
{
    public function __construct(
        private readonly Google2FA $engine,
        private readonly Repository $cache,
    ) {}

    public function generateSecretKey(int $secretLength = 16): string
    {
        return $this->engine->generateSecretKey($secretLength);
    }

    public function qrCodeUrl($companyName, $companyEmail, $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    public function verify($secret, $code): bool
    {
        $window = config('fortify-options.two-factor-authentication.window');

        if (is_int($window)) {
            $this->engine->setWindow($window);
        }

        if (! $this->engine->verifyKey($secret, $code)) {
            return false;
        }

        $cacheKey = 'fortify.2fa_codes.'.hash_hmac(
            'sha256',
            hash('sha256', (string) $secret).'|'.(string) $code,
            (string) config('app.key'),
        );
        $ttl = max(120, (($this->engine->getWindow() * 2) + 1) * 30 + 30);

        return $this->cache->add($cacheKey, true, $ttl);
    }
}
