<?php

namespace App\Providers;

use App\Contracts\Attachments\MalwareScanner;
use App\Contracts\Scheduling\RecurrenceEngine;
use App\Support\Attachments\ClamAvMalwareScanner;
use App\Support\Attachments\FailClosedMalwareScanner;
use App\Support\Auth\SensitiveRateLimitKey;
use App\Support\Scheduling\RlanvinRecurrenceEngine;
use App\Support\Tenancy\RequestDatabaseContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MalwareScanner::class, function (): MalwareScanner {
            return config('lesson-notes.attachments.scanner.driver') === 'clamav'
                ? new ClamAvMalwareScanner
                : new FailClosedMalwareScanner;
        });
        $this->app->singleton(RecurrenceEngine::class, RlanvinRecurrenceEngine::class);
        $this->app->scoped(
            TenantContext::class,
            fn (): TenantContext => new TenantContext($this->app['db']->connection()),
        );
        $this->app->scoped(
            RequestDatabaseContext::class,
            fn (): RequestDatabaseContext => new RequestDatabaseContext($this->app['db']),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $this->assertProductionSecurityLimits();
        }

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('attachment-scans', function (Request $request): Limit {
            $studio = $request->route('studio');

            return Limit::perMinute(5)->by(implode(':', [
                $request->user()?->getAuthIdentifier() ?? 'guest',
                $studio instanceof UrlRoutable ? $studio->getRouteKey() : (string) $studio,
                (string) $request->route('attachment'),
            ]));
        });
        RateLimiter::for('invitation-create', function (Request $request): array {
            $studio = $request->route('studio')
                ?? $request->attributes->get('invitation_rate_limit_studio');
            $studioId = $studio instanceof UrlRoutable
                ? $studio->getRouteKey()
                : (string) $studio;
            $keys = app(SensitiveRateLimitKey::class);

            return [
                Limit::perHour(20)->by($keys->for(
                    'invitation-create-inviter',
                    $request->user()?->getAuthIdentifier() ?? 'guest',
                )),
                Limit::perDay(100)->by($keys->for(
                    'invitation-create-studio',
                    $studioId,
                )),
            ];
        });
        RateLimiter::for('invitation-resend', function (Request $request): Limit {
            $invitation = $request->route('invitation')
                ?? $request->attributes->get('invitation_rate_limit_invitation');
            $invitationId = $invitation instanceof UrlRoutable
                ? $invitation->getRouteKey()
                : (string) $invitation;

            return Limit::perMinute(1)->by(app(SensitiveRateLimitKey::class)->for(
                'invitation-resend',
                $request->user()?->getAuthIdentifier() ?? 'guest',
                $invitationId,
                $request->ip() ?? 'unknown',
            ));
        });
    }

    private function assertProductionSecurityLimits(): void
    {
        $limits = [
            'AUTH_PASSWORD_TIMEOUT' => [(int) config('auth.password_timeout'), 600],
            'SESSION_LIFETIME' => [(int) config('security.session_idle_minutes'), (int) config('security.session_max_idle_minutes')],
            'SESSION_ABSOLUTE_LIFETIME_MINUTES' => [(int) config('security.session_absolute_minutes'), (int) config('security.session_max_absolute_minutes')],
            'TWO_FACTOR_CHALLENGE_SECONDS' => [(int) config('security.two_factor_challenge_seconds'), 300],
            'TWO_FACTOR_SETUP_SECONDS' => [(int) config('security.two_factor_setup_seconds'), 600],
        ];

        foreach ($limits as $name => [$configured, $maximum]) {
            if ($configured < 1 || $configured > $maximum) {
                throw new LogicException("{$name} must be between 1 and {$maximum} in production.");
            }
        }

        if (config('session.driver') !== 'database') {
            throw new LogicException('SESSION_DRIVER must be database in production.');
        }

        if (config('mail.default') === 'log') {
            throw new LogicException('MAIL_MAILER must not be log in production because invitation emails contain one-time bearer links.');
        }

        $passkeySecret = (string) config('fortify.passkeys.user_handle_secret');
        $appKey = (string) config('app.key');

        if ($passkeySecret === '' || hash_equals($appKey, $passkeySecret) || $this->secretBytes($passkeySecret) < 32) {
            throw new LogicException('PASSKEYS_USER_HANDLE_SECRET must be an independent secret of at least 32 bytes in production.');
        }

        $invitationSecret = (string) config('services.invitations.token_secret');

        if ($invitationSecret === ''
            || hash_equals($appKey, $invitationSecret)
            || $this->secretBytes($invitationSecret) < 32) {
            throw new LogicException('INVITATION_TOKEN_SECRET must be an independent secret of at least 32 bytes in production.');
        }

        $relyingParty = (string) config('fortify.passkeys.relying_party_id');
        $origins = config('fortify.passkeys.allowed_origins', []);

        if ($relyingParty === '' || ! is_array($origins) || $origins === []) {
            throw new LogicException('Passkey relying party and allowed origins must be configured in production.');
        }

        foreach ($origins as $origin) {
            $parts = is_string($origin) ? parse_url($origin) : false;

            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! isset($parts['host'])
                || rtrim($origin, '/') !== $origin) {
                throw new LogicException('PASSKEYS_ALLOWED_ORIGINS must contain exact HTTPS origins without trailing slashes in production.');
            }
        }

        $minimumAttachmentBytes = (int) config('lesson-notes.attachments.minimum_bytes');
        $maximumAttachmentBytes = (int) config('lesson-notes.attachments.maximum_bytes');
        $maximumAttachmentsPerNote = (int) config('lesson-notes.attachments.maximum_active_per_note');
        $maximumAttachmentBytesPerNote = (int) config('lesson-notes.attachments.maximum_active_bytes_per_note');
        $downloadMinutes = (int) config('lesson-notes.attachments.download_url_minutes');
        $attachmentDisk = (string) config('lesson-notes.attachments.disk');
        $scannerDriver = (string) config('lesson-notes.attachments.scanner.driver');
        $quarantineRetentionHours = (int) config('lesson-notes.attachments.quarantine_retention_hours');
        $pendingRetentionHours = (int) config('lesson-notes.attachments.pending_retention_hours');
        $retiredRetentionHours = (int) config('lesson-notes.attachments.retired_retention_hours');
        $purgeBatchSize = (int) config('lesson-notes.attachments.purge_batch_size');
        if ($minimumAttachmentBytes < 1 || $maximumAttachmentBytes < $minimumAttachmentBytes) {
            throw new LogicException('Lesson attachment size bounds are invalid.');
        }
        if ($maximumAttachmentsPerNote < 1 || $maximumAttachmentBytesPerNote < $maximumAttachmentBytes) {
            throw new LogicException('Lesson attachment per-note bounds are invalid.');
        }
        if ($downloadMinutes < 1 || $downloadMinutes > 15) {
            throw new LogicException('LESSON_ATTACHMENT_DOWNLOAD_URL_MINUTES must be between 1 and 15.');
        }
        if ($quarantineRetentionHours < 1 || $pendingRetentionHours < 1 || $retiredRetentionHours < 1
            || $purgeBatchSize < 1 || $purgeBatchSize > 1000) {
            throw new LogicException('Lesson attachment retention and purge batch settings are invalid.');
        }
        if ($attachmentDisk === '' || config("filesystems.disks.{$attachmentDisk}") === null) {
            throw new LogicException('LESSON_ATTACHMENT_DISK must name a configured private filesystem disk.');
        }
        if ($scannerDriver !== 'clamav') {
            throw new LogicException('LESSON_ATTACHMENT_SCANNER must be clamav in production.');
        }
        if (trim((string) config('lesson-notes.attachments.scanner.clamav.host')) === ''
            || (int) config('lesson-notes.attachments.scanner.clamav.port') < 1
            || (int) config('lesson-notes.attachments.scanner.clamav.port') > 65535
            || (float) config('lesson-notes.attachments.scanner.clamav.timeout_seconds') <= 0) {
            throw new LogicException('ClamAV host, port, and timeout must be configured safely in production.');
        }
    }

    private function secretBytes(string $secret): int
    {
        if (! str_starts_with($secret, 'base64:')) {
            return strlen($secret);
        }

        $decoded = base64_decode(substr($secret, 7), true);

        return $decoded === false ? 0 : strlen($decoded);
    }
}
