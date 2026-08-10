<?php

namespace App\Notifications;

use App\Models\StudioInvitation;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StudioInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private ?StudioInvitation $deliverableInvitation = null;

    public function __construct(
        private readonly string $invitationId,
        #[\SensitiveParameter] private readonly string $token,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->deliverableInvitation ?? $this->findInvitation();

        if ($invitation === null || ! $invitation->isPending()) {
            throw new LogicException('A non-pending studio invitation cannot be delivered.');
        }

        $query = http_build_query(['invite' => $this->token], '', '&', PHP_QUERY_RFC3986);
        $url = rtrim((string) config('services.frontend.url'), '/').'/register#'.$query;

        return (new MailMessage)
            ->subject('You have been invited to Maestro')
            ->line('You have been invited to join '.$invitation->studio->name.'.')
            ->action('Review invitation', $url)
            ->line('This invitation expires on '.$invitation->expires_at->toDayDateTimeString().'.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $invitation = $this->findInvitation();
        $this->deliverableInvitation = $invitation?->isPending() ? $invitation : null;

        return $this->deliverableInvitation !== null;
    }

    private function findInvitation(): ?StudioInvitation
    {
        return DB::transaction(function (): ?StudioInvitation {
            app(RequestDatabaseContext::class)->activateInvitationToken($this->token);

            return StudioInvitation::query()
                ->with('studio')
                ->whereKey($this->invitationId)
                ->where('token_hash', hash('sha256', $this->token))
                ->first();
        });
    }
}
