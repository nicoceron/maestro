<?php

namespace App\SupportAccess\Http\Controllers;

use App\Audit\Http\Resources\TenantAuditEventResource;
use App\Audit\Models\TenantAuditEvent;
use App\SupportAccess\Models\SupportAccessSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SupportSessionAuditController
{
    public function __invoke(Request $request, SupportAccessSession $session): AnonymousResourceCollection
    {
        /** @var SupportAccessSession $resolved */
        $resolved = $request->attributes->get('support_access_session');

        return TenantAuditEventResource::collection(TenantAuditEvent::query()
            ->where('studio_id', $resolved->studio_id)->orderByDesc('stream_sequence')->cursorPaginate(50));
    }
}
