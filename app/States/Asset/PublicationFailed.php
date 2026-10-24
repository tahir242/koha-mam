<?php

namespace App\States\Asset;

class PublicationFailed extends AssetState
{
    public static $name = 'publication_failed';

    public function label(): string
    {
        return 'Publication failed';
    }

    public function tone(): string
    {
        return 'red';
    }
}
