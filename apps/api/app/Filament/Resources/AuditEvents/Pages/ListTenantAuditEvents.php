<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\TenantAuditEventResource;
use Filament\Resources\Pages\ListRecords;

final class ListTenantAuditEvents extends ListRecords
{
    protected static string $resource = TenantAuditEventResource::class;
}
