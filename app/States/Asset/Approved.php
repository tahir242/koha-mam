<?php

namespace App\States\Asset;

class Approved extends AssetState
{
    public static $name = 'approved';

    public function label(): string
    {
        return 'Approved';
    }

    public function tone(): string
    {
        return 'teal';
    }
}
