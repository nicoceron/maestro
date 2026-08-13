<?php

namespace App\DataLifecycle\Actions;

use App\DataLifecycle\Enums\TenantRestoreDrillStatus;
use App\DataLifecycle\Jobs\RunTenantRestoreDrill;
use App\DataLifecycle\Models\TenantRestoreDrill;
use App\DataLifecycle\Support\TenantDataLifecycleAudit;
use App\Models\User;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Models\TenantDataExport;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestTenantRestoreDrill
{
    public function __construct(private readonly TenantDataLifecycleAudit $audit) {}

    public function handle(TenantDataExport $export, User $actor, ?string $targetStudioId = null): TenantRestoreDrill
    {
        if ($export->status !== TenantDataExportStatus::Ready || $export->expires_at?->isPast()) {
            throw new DomainException('EXPORT_NOT_RESTORABLE');
        }

        return DB::transaction(function () use ($export, $actor, $targetStudioId): TenantRestoreDrill {
            $proposedTarget = $targetStudioId ?? (string) Str::ulid();
            $drill = TenantRestoreDrill::query()->create([
                'studio_id' => $export->studio_id,
                'export_id' => $export->getKey(),
                'requested_by_id' => $actor->getAuthIdentifier(),
                'status' => TenantRestoreDrillStatus::Requested,
                'dry_run' => true,
                'target_studio_id' => $targetStudioId,
                'tenant_id_remap' => [
                    (string) $export->studio_id => $proposedTarget,
                ],
            ]);
            $this->audit->record($drill, 'tenant_restore_drill', 'tenant.restore_drill.requested', null, TenantRestoreDrillStatus::Requested->value, $actor, [
                'export_id' => $export->getKey(),
                'dry_run' => true,
            ]);
            RunTenantRestoreDrill::dispatch($drill->getKey(), (string) $drill->studio_id)->afterCommit();

            return $drill;
        });
    }
}
