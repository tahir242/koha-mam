<?php

namespace App\Koha\Exceptions;

/** Network failure, timeout, 5xx, 503 maintenance or 429 throttling. */
class KohaUnavailableException extends KohaException
{
    public function isTransient(): bool
    {
        return true;
    }

    public function kind(): string
    {
        return 'unavailable';
    }
}
