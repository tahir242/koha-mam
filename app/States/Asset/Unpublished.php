<?php

namespace App\States\Asset;

class Unpublished extends AssetState
{
    public static $name = 'unpublished';

    public function label(): string
    {
        return 'Unpublished';
    }

    public function tone(): string
    {
        return 'zinc';
    }
}
