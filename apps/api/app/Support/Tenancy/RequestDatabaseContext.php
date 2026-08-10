<?php

namespace App\Support\Tenancy;

use App\Models\User;
use Illuminate\Database\DatabaseManager;

final class RequestDatabaseContext
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function activateUser(User $user): void
    {
        $this->set('app.current_user_id', (string) $user->getAuthIdentifier(), false);
    }

    public function clearUser(): void
    {
        $this->set('app.current_user_id', '', false);
    }

    public function activateInvitationToken(string $token): void
    {
        $this->set('app.current_invitation_token_hash', hash('sha256', $token), true);
    }

    private function set(string $key, string $value, bool $local): void
    {
        $connection = $this->database->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'select set_config(?, ?, ?)',
            [$key, $value, $local],
        );
    }
}
