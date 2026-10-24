<?php

namespace App\Enums;

/**
 * MARC21 856 second indicator: relationship between the electronic
 * resource and the bibliographic item described by the record.
 */
enum MarcRelationship: string
{
    case Resource = 'resource';
    case Version = 'version';
    case Related = 'related';
    case Unspecified = 'unspecified';

    public function label(): string
    {
        return match ($this) {
            self::Resource => 'The resource itself (ind2 0)',
            self::Version => 'Version of the resource (ind2 1)',
            self::Related => 'Related resource (ind2 2)',
            self::Unspecified => 'No information provided (ind2 blank)',
        };
    }
}
