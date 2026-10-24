<?php

namespace App\States\Asset;

class Archived extends AssetState
{
    public static $name = 'archived';

    public function label(): string
    {
        return 'Archived';
    }

    public function tone(): string
    {
        return 'zinc';
    }
}
