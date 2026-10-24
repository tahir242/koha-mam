<?php

namespace App\States\Asset;

class Validating extends AssetState
{
    public static $name = 'validating';

    public function label(): string
    {
        return 'Validating';
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
