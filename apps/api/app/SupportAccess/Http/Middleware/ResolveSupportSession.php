<?php

namespace App\SupportAccess\Http\Middleware;

use App\Models\User;
use App\SupportAccess\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportSessionToken;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveSupportSession
{
    public function __construct(private readonly SupportSessionToken $tokens, private readonly ConnectionInterface $connection) {}

    public function handle(Request $request, Closure $next, string $requiredScope = 'session.manage'): Response
    {
        $user = $request->user();
        $plain = $request->header('X-Maestro-Support-Session');
        if (! $user instanceof User || ! is_string($plain) || strlen($plain) < 40 || strlen($plain) > 100) {
            throw new SupportAccessException('support_session_required', 'An active support session is required.', 401);
        }

        $session = SupportAccessSession::query()
            ->with(['grant', 'studio', 'approver'])
            ->where('support_user_id', $user->getAuthIdentifier())
            ->where('token_hash', $this->tokens->hash($plain))->first();
        if ($session === null || ! $session->isActive()) {
            throw new SupportAccessException('support_session_inactive', 'The support session is invalid, expired, ended, or revoked.', 401);
        }
        if ($requiredScope !== 'session.manage' && ! in_array($requiredScope, $session->scopes, true)) {
            throw new SupportAccessException('support_scope_forbidden', 'The support session does not include the required scope.', 403);
        }
        $routeSession = $request->route('session');
        if ($routeSession instanceof SupportAccessSession && ! $routeSession->is($session)) {
            throw new SupportAccessException('support_session_mismatch', 'The support session header does not match the route.', 403);
        }

        $previousSession = '';
        if ($this->connection->getDriverName() === 'pgsql') {
            $previousSession = (string) ($this->connection->scalar("select current_setting('app.current_support_session_id', true)") ?? '');
            $this->connection->statement("select set_config('app.current_support_session_id', ?, false)", [$session->getKey()]);
        }
        $request->attributes->set('support_access_session', $session);

        try {
            $response = $next($request);
            $response->headers->set('X-Maestro-Support-Access', 'active');
            $response->headers->set('X-Maestro-Support-Session-Id', (string) $session->getKey());
            $response->headers->set('X-Maestro-Support-Expires-At', $session->expires_at->toIso8601String());
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        } finally {
            if ($this->connection->getDriverName() === 'pgsql') {
                $this->connection->statement("select set_config('app.current_support_session_id', ?, false)", [$previousSession]);
            }
        }
    }
}
