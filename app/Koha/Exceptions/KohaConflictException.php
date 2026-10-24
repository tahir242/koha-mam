<?php

namespace App\Koha\Exceptions;

/** HTTP 409 from Koha, or a concurrent record modification detected locally. */
class KohaConflictException extends KohaException
{
    public function isTransient(): bool
    {
        return true;
    }

    public function kind(): string
    {
        return 'conflict';
    }
}
