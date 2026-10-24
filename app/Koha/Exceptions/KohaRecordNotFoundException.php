<?php

namespace App\Koha\Exceptions;

class KohaRecordNotFoundException extends KohaException
{
    public function kind(): string
    {
        return 'not_found';
    }
}
