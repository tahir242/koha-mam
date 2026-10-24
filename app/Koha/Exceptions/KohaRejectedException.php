<?php

namespace App\Koha\Exceptions;

/** 400/406/other 4xx: the request itself was refused. Not retried automatically. */
class KohaRejectedException extends KohaException
{
    public function kind(): string
    {
        return 'rejected';
    }
}
