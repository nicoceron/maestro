<?php

namespace App\Actions\Invitations;

use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Support\Audit\InvitationAudit;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Support\Facades\DB;

final class RedactTerminalInvitationDigests
{
    public function __construct(
        private readonly RequestDatabaseContext $databaseContext,
        private readonly InvitationAudit $audit,
    ) {}

    public function handle(): int
    {
        $redacted = 0;
        $cutoff = now()->subDays(30);

        Studio::query()->orderBy('id')->eachById(function (Studio $studio) use (&$redacted, $cutoff): void {
            DB::transaction(function () use ($studio, &$redacted, $cutoff): void {
                $this->databaseContext->activateStudioId((string) $studio->getKey());

                $invitations = StudioInvitation::query()
                    ->where('studio_id', $studio->getKey())
                    ->whereNotNull('token_hash')
                    ->where(function ($query) use ($cutoff): void {
                        $query->where('accepted_at', '<=', $cutoff)
                            ->orWhere('revoked_at', '<=', $cutoff)
                            ->orWhere('superseded_at', '<=', $cutoff)
                            ->orWhere(function ($query) use ($cutoff): void {
                                $query->whereNull('accepted_at')
                                    ->whereNull('revoked_at')
                                    ->whereNull('superseded_at')
                                    ->where('expires_at', '<=', $cutoff);
                            });
                    })
                    ->lockForUpdate()
                    ->get();

                foreach ($invitations as $invitation) {
                    $invitation->forceFill([
                        'token_hash' => null,
                        'token_redacted_at' => now(),
                    ])->save();
                    StudioInvitationDelivery::query()
                        ->where('invitation_id', $invitation->getKey())
                        ->whereNull('redacted_at')
                        ->update(['redacted_at' => now(), 'updated_at' => now()]);
                    $this->audit->record($invitation, 'invitation.digest_redacted', metadata: [
                        'delivery_version' => $invitation->delivery_version,
                    ], includeRequestMetadata: false);
                    $redacted++;
                }
            });
        }, column: 'id');

        return $redacted;
    }
}
