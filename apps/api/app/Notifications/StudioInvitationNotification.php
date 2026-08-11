<?php

namespace App\Notifications;

use App\Models\StudioInvitationDelivery;
use App\Support\Auth\InvitationToken;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;

final class StudioInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $invitationId,
        private readonly int $deliveryVersion,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $delivery = StudioInvitationDelivery::query()
            ->with('invitation')
            ->where('invitation_id', $this->invitationId)
            ->where('delivery_version', $this->deliveryVersion)
            ->first();

        return $delivery?->status === 'pending' && $delivery->invitation?->isPending() === true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $delivery = StudioInvitationDelivery::query()
            ->with('invitation.studio')
            ->where('invitation_id', $this->invitationId)
            ->where('delivery_version', $this->deliveryVersion)
            ->first();
        $invitation = $delivery?->invitation;

        if ($delivery === null
            || ! in_array($delivery->status, ['pending', 'sent'], true)
            || $invitation === null
            || ! $invitation->isPending()) {
            throw new LogicException('A non-pending studio invitation cannot be delivered.');
        }

        $tokens = app(InvitationToken::class);
        $token = $tokens->derive($this->invitationId, $this->deliveryVersion);

        if ($invitation->token_hash === null
            || ! hash_equals($invitation->token_hash, $tokens->digest($token))) {
            throw new LogicException('The invitation delivery token is unavailable.');
        }

        $query = http_build_query(['invite' => $token], '', '&', PHP_QUERY_RFC3986);
        $url = rtrim((string) config('services.frontend.url'), '/').'/register#'.$query;

        return (new MailMessage)
            ->subject('You have been invited to Maestro')
            ->line('You have been invited to join '.$invitation->studio->name.'.')
            ->action('Review invitation', $url)
            ->line('This invitation expires on '.$invitation->expires_at->toDayDateTimeString().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
