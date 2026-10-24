<?php

namespace App\States\Asset;

class Draft extends AssetState
{
    public static $name = 'draft';

    public function label(): string
    {
        return 'Draft';
    }

    public function tone(): string
    {
        return 'zinc';
    }
}
