<?php

namespace App\SupportAccess;

final class SupportSessionToken
{
    /** @return array{plain: string, hash: string} */
    public function issue(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['plain' => $plain, 'hash' => $this->hash($plain)];
    }

    public function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, $this->key());
    }

    private function key(): string
    {
        $configured = (string) config('support-access.token_key', config('app.key'));
        if (str_starts_with($configured, 'base64:')) {
            return base64_decode(substr($configured, 7), true) ?: $configured;
        }

        return $configured;
    }
}
