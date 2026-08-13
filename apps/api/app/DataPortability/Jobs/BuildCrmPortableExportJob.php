<?php

namespace App\DataPortability\Jobs;

use App\Audit\StudioDatabaseScope;
use App\DataPortability\Actions\BuildCrmPortableExport;
use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmDataPortabilityAccess;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class BuildCrmPortableExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public function __construct(public string $studioId, public string $exportId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('crm-export:'.$this->exportId))->expireAfter(960)];
    }

    public function handle(StudioDatabaseScope $scope, TenantContext $context, BuildCrmPortableExport $builder): void
    {
        $scope->run($this->studioId, function () use ($context, $builder): void {
            $export = CrmPortableExport::query()->where('studio_id', $this->studioId)->findOrFail($this->exportId);
            if (in_array($export->status, ['ready', 'purged', 'expired'], true)) {
                return;
            }
            $studio = Studio::query()->find($this->studioId);
            $actor = User::query()->find($export->requested_by_id);
            $membership = $actor ? StudioMembership::query()->where('studio_id', $this->studioId)->where('user_id', $actor->getAuthIdentifier())->where('status', 'active')->first() : null;
            if (! $studio || ! $actor || ! $membership || ! CrmDataPortabilityAccess::allows($actor, $studio)) {
                $export->update(['status' => 'failed', 'error_code' => 'CRM_EXPORT_AUTHORIZATION_REVOKED']);

                return;
            } $context->activate($studio, $membership);
            try {
                $builder->build($studio, $actor, $export);
            } finally {
                $context->clear();
            }
        });
    }
}
