<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BrowserSessionRegistry
{
    public function touch(Request $request, User $user): void
    {
        $sessionId = $request->session()->getId();
        $now = now();
        $connection = $this->connection();

        $connection->table('user_sessions')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'session_id' => $sessionId,
            'user_id' => $user->getAuthIdentifier(),
            'ip_address' => $request->ip(),
            'user_agent' => $this->userAgent($request),
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);

        $connection->table('user_sessions')
            ->where('session_id', $sessionId)
            ->where('user_id', $user->getAuthIdentifier())
            ->update([
                'ip_address' => $request->ip(),
                'user_agent' => $this->userAgent($request),
                'last_seen_at' => $now,
            ]);
    }

    public function isExpired(Request $request, User $user): bool
    {
        $session = $this->connection()->table('user_sessions')
            ->where('session_id', $request->session()->getId())
            ->where('user_id', $user->getAuthIdentifier())
            ->first(['created_at', 'last_seen_at']);

        if ($session === null) {
            return true;
        }

        $idleCutoff = now()->subMinutes((int) config('security.session_idle_minutes'));
        $absoluteCutoff = now()->subMinutes((int) config('security.session_absolute_minutes'));

        return Carbon::parse($session->last_seen_at)->lte($idleCutoff)
            || Carbon::parse($session->created_at)->lte($absoluteCutoff);
    }

    /** @return list<UserSession> */
    public function forUser(User $user): array
    {
        $sessions = UserSession::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->latest('last_seen_at')
            ->get();
        $idleCutoff = now()->subMinutes((int) config('security.session_idle_minutes'));
        $absoluteCutoff = now()->subMinutes((int) config('security.session_absolute_minutes'));
        $backingSessionIds = $this->connection()
            ->table((string) config('session.table', 'sessions'))
            ->whereIn('id', $sessions->pluck('session_id'))
            ->pluck('id')
            ->all();

        return $sessions->filter(function (UserSession $session) use (
            $idleCutoff,
            $absoluteCutoff,
            $backingSessionIds,
        ): bool {
            $active = $session->last_seen_at->gt($idleCutoff)
                && $session->created_at->gt($absoluteCutoff)
                && in_array($session->session_id, $backingSessionIds, true);

            if (! $active) {
                $this->revoke($session);
            }

            return $active;
        })->values()->all();
    }

    public function revoke(UserSession $session): void
    {
        $this->connection()->transaction(function () use ($session): void {
            $this->connection()->table((string) config('session.table', 'sessions'))
                ->where('id', $session->session_id)
                ->delete();
            $session->delete();
        });
    }

    public function revokeOthers(User $user, string $currentSessionId): void
    {
        $this->connection()->transaction(function () use ($user, $currentSessionId): void {
            $sessions = UserSession::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('session_id', '!=', $currentSessionId)
                ->get();

            $this->connection()->table((string) config('session.table', 'sessions'))
                ->whereIn('id', $sessions->pluck('session_id'))
                ->delete();

            UserSession::query()
                ->whereKey($sessions->modelKeys())
                ->delete();
        });
    }

    public function revokeCurrent(Request $request, User $user): void
    {
        $session = UserSession::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_id', $request->session()->getId())
            ->first();

        if ($session !== null) {
            $this->revoke($session);
        }
    }

    public function revokeBySessionId(User $user, string $sessionId): void
    {
        $session = UserSession::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('session_id', $sessionId)
            ->first();

        if ($session !== null) {
            $this->revoke($session);
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(config('session.connection'));
    }

    private function userAgent(Request $request): ?string
    {
        $userAgent = $request->userAgent();

        return $userAgent === null ? null : Str::limit($userAgent, 1000, '');
    }
}
