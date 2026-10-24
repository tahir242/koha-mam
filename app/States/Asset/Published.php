<?php

namespace App\States\Asset;

class Published extends AssetState
{
    public static $name = 'published';

    public function label(): string
    {
        return 'Published';
    }

    public function tone(): string
    {
        return 'emerald';
    }

    public function isPubliclyVisible(): bool
    {
        return true;
    }
}
