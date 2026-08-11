<?php

namespace App\Exceptions;

use RuntimeException;

final class InvitationDeliveryFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The invitation delivery provider failed.');
    }
}
