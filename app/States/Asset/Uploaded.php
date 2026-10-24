<?php

namespace App\States\Asset;

class Uploaded extends AssetState
{
    public static $name = 'uploaded';

    public function label(): string
    {
        return 'Uploaded';
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
