<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Auth\BrowserSessionRegistry;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UserSessionController extends Controller
{
    public function __construct(private readonly BrowserSessionRegistry $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureDatabaseSessions();
        /** @var User $user */
        $user = $request->user();
        $currentSessionId = $request->session()->getId();
        $sessions = collect($this->sessions->forUser($user))->map(
            fn (UserSession $session): array => $this->serialize($session, $currentSessionId),
        );

        return response()->json(['data' => $sessions->values()]);
    }

    public function destroyOthers(Request $request): Response
    {
        $this->ensureDatabaseSessions();
        /** @var User $user */
        $user = $request->user();
        $this->sessions->revokeOthers($user, $request->session()->getId());

        return response()->noContent();
    }

    public function destroy(
        Request $request,
        UserSession $userSession,
        StatefulGuard $guard,
    ): Response {
        $this->ensureDatabaseSessions();
        /** @var User $user */
        $user = $request->user();
        abort_unless($userSession->user_id === $user->getAuthIdentifier(), 404);
        $isCurrent = hash_equals($request->session()->getId(), $userSession->session_id);

        $this->sessions->revoke($userSession);

        if ($isCurrent) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function serialize(UserSession $session, string $currentSessionId): array
    {
        return [
            'id' => $session->getKey(),
            'current' => hash_equals($currentSessionId, $session->session_id),
            'device' => $this->deviceLabel($session->user_agent),
            'approximate_location' => $this->approximateNetwork($session->ip_address),
            'created_at' => $session->created_at->toIso8601String(),
            'last_seen_at' => $session->last_seen_at->toIso8601String(),
        ];
    }

    private function deviceLabel(?string $userAgent): string
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$platform}";
    }

    private function approximateNetwork(?string $ipAddress): ?string
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = explode('.', $ipAddress);
            $octets[3] = '0';

            return implode('.', $octets).'/24 (approximate)';
        }

        $binary = inet_pton($ipAddress);

        if ($binary === false) {
            return null;
        }

        $masked = substr($binary, 0, 6).str_repeat("\0", 10);

        return inet_ntop($masked).'/48 (approximate)';
    }

    private function ensureDatabaseSessions(): void
    {
        abort_unless(
            config('session.driver') === 'database',
            409,
            'Session management requires the database session driver.',
        );
    }
}
