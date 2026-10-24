<?php

namespace App\States\Asset;

class ReadyForReview extends AssetState
{
    public static $name = 'ready_for_review';

    public function label(): string
    {
        return 'Ready for review';
    }

    public function tone(): string
    {
        return 'indigo';
    }
}
