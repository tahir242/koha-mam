<?php

namespace App\Koha\Exceptions;

/** 401/403 or a misconfigured API client. Requires administrator action. */
class KohaAuthorizationException extends KohaException
{
    public function kind(): string
    {
        return 'unauthorized';
    }
}
