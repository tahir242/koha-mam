<?php

namespace App\States\Asset;

class PublicationPending extends AssetState
{
    public static $name = 'publication_pending';

    public function label(): string
    {
        return 'Publishing';
    }

    public function tone(): string
    {
        return 'cyan';
    }
}
