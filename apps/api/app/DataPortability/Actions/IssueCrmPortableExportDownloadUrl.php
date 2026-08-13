<?php

namespace App\DataPortability\Actions;

use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

final class IssueCrmPortableExportDownloadUrl
{
    /** @return array{url:string,expires_at:string} */
    public function handle(Studio $studio, User $actor, CrmPortableExport $export): array
    {
        Gate::forUser($actor)->authorize('download', $export);
        if ($export->status !== 'ready' || $export->expires_at->isPast() || $export->download_count !== 0) {
            throw new CrmDataPortabilityConflict('CRM_EXPORT_NOT_DOWNLOADABLE');
        }
        $expires = now()->addMinutes(5);

        return [
            'url' => URL::temporarySignedRoute('api.v1.data-portability.exports.download', $expires, ['studio' => $studio, 'export' => $export]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }
}
