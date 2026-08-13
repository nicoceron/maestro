<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Actions\RequestTenantDataExport;
use DomainException;
use Illuminate\Support\Facades\DB;

final class RequestTenantDeletion
{
    public function __construct(
        private readonly RequestTenantDataExport $requestExport,
        private readonly TenantDataLifecycleAudit $audit,
    ) {}

    public function handle(
        Studio $studio,
        User $actor,
        string $reason,
        string $confirmationPhrase,
        string $idempotencyKey,
    ): TenantDeletionRequest {
        $expectedPhrase = "DELETE {$studio->slug}";
        if (! hash_equals($expectedPhrase, $confirmationPhrase)) {
            throw new DomainException('CONFIRMATION_PHRASE_INVALID');
        }
        $fingerprint = hash('sha256', json_encode([
            'studio_id' => (string) $studio->getKey(),
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($studio, $actor, $reason, $confirmationPhrase, $idempotencyKey, $fingerprint): TenantDeletionRequest {
            $existing = TenantDeletionRequest::query()
                ->where('studio_id', $studio->getKey())
                ->where('requested_by_id', $actor->getAuthIdentifier())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DomainException('IDEMPOTENCY_KEY_REUSED');
                }

                return $existing;
            }

            if (TenantDeletionRequest::query()->where('studio_id', $studio->getKey())->whereNotIn('status', [
                TenantDeletionStatus::Cancelled,
                TenantDeletionStatus::Restored,
            ])->exists()) {
                throw new DomainException('DELETION_ALREADY_OPEN');
            }

            $policy = TenantRetentionPolicy::query()->firstOrCreate(
                ['studio_id' => $studio->getKey()],
                [
                    'export_ttl_hours' => config('tenant-data.export_ttl_hours'),
                    'deletion_cooling_off_days' => config('tenant-data.cooling_off_days'),
                    'deletion_quarantine_days' => config('tenant-data.quarantine_days'),
                    'operational_retention_days' => config('tenant-data.default_retention_days'),
                    'media_retention_days' => config('tenant-data.default_retention_days'),
                    'audit_retention_days' => config('tenant-data.default_retention_days'),
                ],
            );
            if ($policy->legal_hold) {
                throw new DomainException('LEGAL_HOLD_ACTIVE');
            }

            $export = $this->requestExport->handle(
                $studio,
                $actor,
                "deletion:{$idempotencyKey}",
                true,
            );
            $deletionArchiveExpiresAt = now()
                ->addDays($policy->deletion_cooling_off_days + $policy->deletion_quarantine_days)
                ->addHours($policy->export_ttl_hours);
            if ($export->expires_at->lt($deletionArchiveExpiresAt)) {
                $export->forceFill(['expires_at' => $deletionArchiveExpiresAt])->save();
            }
            $request = TenantDeletionRequest::query()->create([
                'studio_id' => $studio->getKey(),
                'requested_by_id' => $actor->getAuthIdentifier(),
                'export_id' => $export->getKey(),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'status' => TenantDeletionStatus::CoolingOff,
                'reason' => $reason,
                'confirmation_phrase_digest' => hash_hmac('sha256', $confirmationPhrase, (string) config('app.key')),
                'cooling_off_ends_at' => now()->addDays($policy->deletion_cooling_off_days),
            ]);
            $this->audit->record(
                $request,
                'tenant_deletion',
                'tenant.deletion.cooling_off_started',
                TenantDeletionStatus::Requested->value,
                TenantDeletionStatus::CoolingOff->value,
                $actor,
                ['export_id' => $export->getKey(), 'cooling_off_ends_at' => $request->cooling_off_ends_at->toIso8601String()],
            );

            return $request;
        });
    }
}
