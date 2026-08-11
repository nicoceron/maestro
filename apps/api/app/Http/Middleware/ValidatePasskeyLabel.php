<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidatePasskeyLabel
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('passkey.store')) {
            if (is_string($request->input('name'))) {
                $request->merge(['name' => trim($request->input('name'))]);
            }

            $request->validate([
                'name' => ['required', 'string', 'max:80', 'regex:/^[^\p{C}]+$/u'],
            ]);
        }

        return $next($request);
    }
}
