<?php

namespace App\Actions\Auth;

use App\Models\StudioInvitation;
use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;

final class RegisterPublicUser
{
    public function __construct(
        private readonly RequestDatabaseContext $databaseContext,
        private readonly Timebox $timebox,
    ) {}

    /** @param  array{name: string, email: string, password: string, invitation_token?: string|null}  $input */
    public function handle(#[\SensitiveParameter] array $input): void
    {
        $this->timebox->call(function () use ($input): void {
            // Perform the expensive operation for every accepted request, including
            // existing accounts and unusable invitations, before checking persistence.
            $password = Hash::make($input['password']);
            $user = DB::transaction(function () use ($input, $password): ?User {
                $token = $input['invitation_token'] ?? null;

                if (is_string($token) && $token !== '') {
                    $this->databaseContext->activateInvitationToken($token);
                    $invitation = StudioInvitation::query()
                        ->where('token_hash', hash('sha256', $token))
                        ->first();

                    if ($invitation === null
                        || ! $invitation->isPending()
                        || ! hash_equals($invitation->email_normalized, $input['email'])) {
                        return null;
                    }
                }

                // createOrFirst relies on the database unique index and safely turns a
                // concurrent insert race into the same no-op used for an existing user.
                $user = User::query()->createOrFirst(
                    ['email' => $input['email']],
                    [
                        'name' => trim($input['name']),
                        'password' => $password,
                    ],
                );

                return $user->wasRecentlyCreated ? $user->refresh() : null;
            });

            if ($user !== null) {
                event(new Registered($user));
            }
        }, (int) config('security.registration_timebox_microseconds', 300_000));
    }
}
