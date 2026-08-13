<?php

namespace App\DataLifecycle\Enums;

enum TenantRestoreDrillStatus: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Verified = 'verified';
    case Failed = 'failed';
}
