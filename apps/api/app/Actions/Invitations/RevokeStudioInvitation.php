<?php

namespace App\Actions\Invitations;

use App\Models\StudioInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevokeStudioInvitation
{
    /** @throws ValidationException */
    public function handle(StudioInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $locked = StudioInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'invitation' => ['An accepted invitation cannot be revoked.'],
                ]);
            }

            if ($locked->revoked_at === null) {
                $locked->forceFill([
                    'revoked_at' => now(),
                    'pending_key' => null,
                ])->save();
            }
        });
    }
}
