<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTrustedRequestOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $source = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        if ($source !== null && ! in_array($this->origin($source), $this->allowedOrigins(), true)) {
            abort(Response::HTTP_FORBIDDEN, 'Untrusted request origin.');
        }

        return $next($request);
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        return array_values(array_filter(array_map(
            fn (string $origin): ?string => $this->origin($origin),
            config('security.request_origins', []),
        )));
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $origin = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }
}
