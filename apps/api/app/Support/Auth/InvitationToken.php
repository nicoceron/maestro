<?php

namespace App\Support\Auth;

use LogicException;

final class InvitationToken
{
    public function derive(string $invitationId, int $deliveryVersion): string
    {
        $mac = hash_hmac(
            'sha256',
            "maestro-invitation:v1\0{$invitationId}\0{$deliveryVersion}",
            $this->secret(),
            true,
        );

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    public function digestFor(string $invitationId, int $deliveryVersion): string
    {
        return $this->digest($this->derive($invitationId, $deliveryVersion));
    }

    private function secret(): string
    {
        $secret = (string) config('services.invitations.token_secret');
        $appKey = (string) config('app.key');

        if (strlen($secret) < 32
            || (! app()->environment(['local', 'testing']) && hash_equals($appKey, $secret))) {
            throw new LogicException(
                'INVITATION_TOKEN_SECRET must be an independent secret of at least 32 bytes.',
            );
        }

        return $secret;
    }
}
