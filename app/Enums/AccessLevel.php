<?php

namespace App\Enums;

enum AccessLevel: string
{
    /** Anyone may view the published resource without logging in. */
    case Public = 'public';

    /** Only authenticated MAM staff may view the published resource. */
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Restricted => 'Restricted to staff',
        };
    }
}
