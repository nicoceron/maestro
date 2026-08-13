<?php

namespace App\TenantData\Enums;

enum TenantDataExportStatus: string
{
    case Requested = 'requested';
    case Queued = 'queued';
    case Exporting = 'exporting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
    case Purged = 'purged';
}
