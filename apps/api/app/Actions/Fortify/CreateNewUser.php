<?php

namespace App\Actions\Fortify;

use App\Models\StudioInvitation;
use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private readonly RequestDatabaseContext $databaseContext) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = User::normalizeEmail((string) ($input['email'] ?? ''));

        Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'invitation_token' => ['nullable', 'string', 'min:40', 'max:128'],
        ], [
            'email.unique' => 'We could not create an account with those details.',
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $token = $input['invitation_token'] ?? null;

            if (is_string($token) && $token !== '') {
                $this->databaseContext->activateInvitationToken($token);
                $invitation = StudioInvitation::query()
                    ->where('token_hash', hash('sha256', $token))
                    ->first();

                if ($invitation === null
                    || ! $invitation->isPending()
                    || $invitation->email_normalized !== $input['email']) {
                    throw ValidationException::withMessages([
                        'invitation_token' => ['This invitation cannot be used.'],
                    ]);
                }
            }

            $user = User::query()->create([
                'name' => trim((string) $input['name']),
                'email' => $input['email'],
                'password' => Hash::make((string) $input['password']),
            ]);

            return $user->refresh();
        });
    }
}
