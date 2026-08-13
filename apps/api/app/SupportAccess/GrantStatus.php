<?php

namespace App\SupportAccess;

enum GrantStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
