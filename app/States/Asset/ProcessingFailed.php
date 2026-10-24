<?php

namespace App\States\Asset;

class ProcessingFailed extends AssetState
{
    public static $name = 'processing_failed';

    public function label(): string
    {
        return 'Processing failed';
    }

    public function tone(): string
    {
        return 'red';
    }
}
