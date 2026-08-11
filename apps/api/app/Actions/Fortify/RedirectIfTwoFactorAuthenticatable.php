<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

final class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    protected function validateCredentials($request)
    {
        $credentials = $request->only(Fortify::username(), 'password');

        if (! $this->guard->validate($credentials)) {
            $this->fireFailedEvent($request, $this->guard->getLastAttempted());
            $this->throwFailedAuthenticationException($request);
        }

        $user = $this->guard->getLastAttempted();

        if (config('hashing.rehash_on_login', true)
            && method_exists($this->guard->getProvider(), 'rehashPasswordIfRequired')) {
            $this->guard->getProvider()->rehashPasswordIfRequired($user, $credentials);
        }

        return $user;
    }

    protected function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->put('login.issued_at', now()->timestamp);

        return parent::twoFactorChallengeResponse($request, $user);
    }
}
