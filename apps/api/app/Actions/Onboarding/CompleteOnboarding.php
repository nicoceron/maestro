<?php

namespace App\Actions\Onboarding;

use App\Actions\Invitations\AcceptStudioInvitation;
use App\Actions\Studios\CreateStudio;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CompleteOnboarding
{
    public function __construct(
        private readonly AcceptStudioInvitation $acceptInvitation,
        private readonly CreateStudio $createStudio,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $user, array $attributes): Studio
    {
        return DB::transaction(function () use ($user, $attributes): Studio {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $invitationToken = $attributes['invitation_token'] ?? null;

            if (is_string($invitationToken) && $invitationToken !== '') {
                $membership = $this->acceptInvitation->handle($lockedUser, $invitationToken);
                $studio = $membership->studio;
            } else {
                if (! $lockedUser->hasVerifiedEmail()) {
                    throw new AuthorizationException('Your email address is not verified.');
                }

                $membership = StudioMembership::query()
                    ->with('studio')
                    ->where('user_id', $lockedUser->getKey())
                    ->where('status', MembershipStatus::Active)
                    ->orderBy('joined_at')
                    ->orderBy('id')
                    ->first();

                if ($membership === null) {
                    /** @var array<string, mixed> $studioAttributes */
                    $studioAttributes = $attributes['studio'];
                    $studio = $this->createStudio->handle($lockedUser, $studioAttributes);
                    $membership = StudioMembership::query()
                        ->where('studio_id', $studio->getKey())
                        ->where('user_id', $lockedUser->getKey())
                        ->sole();
                } else {
                    $studio = $membership->studio;
                }
            }

            $onboardingPreferences = array_filter([
                'preferred_name' => $attributes['preferred_name'] ?? null,
                'workspace_mode' => $attributes['workspace_mode'] ?? null,
                'primary_goal' => $attributes['primary_goal'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $preferences = is_array($membership->preferences) ? $membership->preferences : [];
            $membership->forceFill([
                'preferences' => array_replace($preferences, $onboardingPreferences),
            ])->save();
            $studio->setRelation('pivot', $membership->refresh());

            return $studio;
        });
    }
}
