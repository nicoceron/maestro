<?php

namespace App\Jobs;

use App\Actions\Invitations\InvitationDeliveryOutbox;
use App\Exceptions\InvitationDeliveryFailed;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Notifications\StudioInvitationNotification;
use App\Support\Audit\InvitationAudit;
use App\Support\Auth\InvitationToken;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DeliverStudioInvitation implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $invitationId,
        public readonly int $deliveryVersion,
    ) {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->invitationId.':'.$this->deliveryVersion;
    }

    public function handle(
        RequestDatabaseContext $databaseContext,
        InvitationDeliveryOutbox $outbox,
        InvitationAudit $audit,
        InvitationToken $tokens,
    ): void {
        DB::transaction(function () use ($databaseContext, $outbox, $audit, $tokens): void {
            $studioId = DB::getDriverName() === 'pgsql'
                ? DB::scalar(
                    'select public.app_resolve_invitation_delivery_studio(?, ?)',
                    [$this->invitationId, $this->deliveryVersion],
                )
                : StudioInvitationDelivery::query()
                    ->where('invitation_id', $this->invitationId)
                    ->where('delivery_version', $this->deliveryVersion)
                    ->value('studio_id');

            if (! is_string($studioId) || $studioId === '') {
                return;
            }

            $databaseContext->activateStudioId($studioId);
            $delivery = StudioInvitationDelivery::query()
                ->where('invitation_id', $this->invitationId)
                ->where('delivery_version', $this->deliveryVersion)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || $delivery->status !== 'pending') {
                return;
            }

            $invitation = StudioInvitation::query()->lockForUpdate()->find($this->invitationId);

            if ($invitation === null || $invitation->delivery_version !== $this->deliveryVersion) {
                return;
            }

            if (! $invitation->isPending()) {
                $outbox->suppressPending($invitation, $invitation->status(), includeRequestMetadata: false);

                return;
            }

            $token = $tokens->derive($this->invitationId, $this->deliveryVersion);

            if ($invitation->token_hash === null
                || ! hash_equals($invitation->token_hash, $tokens->digest($token))) {
                $outbox->suppressPending($invitation, 'token_unavailable', includeRequestMetadata: false);

                return;
            }

            try {
                Notification::route('mail', $invitation->email_normalized)->notifyNow(
                    new StudioInvitationNotification($this->invitationId, $this->deliveryVersion),
                );
            } catch (Throwable) {
                throw new InvitationDeliveryFailed;
            }

            $delivery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            $invitation->forceFill([
                'last_sent_at' => now(),
                'send_count' => $invitation->send_count + 1,
            ])->save();
            $audit->record($invitation, 'invitation.delivered', metadata: [
                'delivery_version' => $delivery->delivery_version,
            ], includeRequestMetadata: false);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $databaseContext = app(RequestDatabaseContext::class);
        $outbox = app(InvitationDeliveryOutbox::class);

        DB::transaction(function () use ($databaseContext, $outbox): void {
            $studioId = DB::getDriverName() === 'pgsql'
                ? DB::scalar(
                    'select public.app_resolve_invitation_delivery_studio(?, ?)',
                    [$this->invitationId, $this->deliveryVersion],
                )
                : StudioInvitationDelivery::query()
                    ->where('invitation_id', $this->invitationId)
                    ->where('delivery_version', $this->deliveryVersion)
                    ->value('studio_id');

            if (! is_string($studioId) || $studioId === '') {
                return;
            }

            $databaseContext->activateStudioId($studioId);
            $invitation = StudioInvitation::query()->lockForUpdate()->find($this->invitationId);

            if ($invitation === null || $invitation->delivery_version !== $this->deliveryVersion) {
                return;
            }

            $outbox->suppressPending(
                $invitation,
                'delivery_failed',
                includeRequestMetadata: false,
            );
        });
    }
}
