<?php

namespace App\Audit\Http\Controllers;

use App\Audit\Http\Resources\TenantAuditEventResource;
use App\Audit\Models\TenantAuditEvent;
use App\Audit\Policies\TenantAuditEventPolicy;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TenantAuditController
{
    public function __invoke(Request $request, Studio $studio, TenantAuditEventPolicy $policy): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user instanceof User && $policy->viewAny($user, $studio), 403);

        $validated = $request->validate([
            'event_type' => ['nullable', 'string', 'max:120'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'correlation_id' => ['nullable', 'ulid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TenantAuditEvent::query()->where('studio_id', $studio->getKey())
            ->when($validated['event_type'] ?? null, fn ($query, $value) => $query->where('event_type', $value))
            ->when($validated['subject_type'] ?? null, fn ($query, $value) => $query->where('subject_type', $value))
            ->when($validated['correlation_id'] ?? null, fn ($query, $value) => $query->where('correlation_id', $value))
            ->when($validated['from'] ?? null, fn ($query, $value) => $query->where('occurred_at', '>=', $value))
            ->when($validated['to'] ?? null, fn ($query, $value) => $query->where('occurred_at', '<=', $value))
            ->orderByDesc('stream_sequence');

        return TenantAuditEventResource::collection($query->cursorPaginate((int) ($validated['per_page'] ?? 50)));
    }
}
