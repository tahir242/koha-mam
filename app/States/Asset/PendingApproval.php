<?php

namespace App\States\Asset;

class PendingApproval extends AssetState
{
    public static $name = 'pending_approval';

    public function label(): string
    {
        return 'Pending approval';
    }

    public function tone(): string
    {
        return 'amber';
    }
}
