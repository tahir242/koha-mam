<?php

namespace App\States\Asset;

class Processing extends AssetState
{
    public static $name = 'processing';

    public function label(): string
    {
        return 'Processing';
    }

    public function tone(): string
    {
        return 'sky';
    }

    public function isProcessing(): bool
    {
        return true;
    }
}
