<?php

namespace App\DataLifecycle\Enums;

enum TenantDeletionStatus: string
{
    case Requested = 'requested';
    case CoolingOff = 'cooling_off';
    case Approved = 'approved';
    case Suspended = 'suspended';
    case Quarantined = 'quarantined';
    case PurgeEligible = 'purge_eligible';
    case Cancelled = 'cancelled';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Restored], true);
    }
}
