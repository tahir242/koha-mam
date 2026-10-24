<?php

namespace App\Policies;

use App\Enums\Capability;
use App\Models\DigitalAsset;
use App\Models\User;
use App\States\Asset\Archived;

class DigitalAssetPolicy
{
    public function view(User $user, DigitalAsset $asset): bool
    {
        return $user->hasCapability(Capability::ViewDashboard);
    }

    public function update(User $user, DigitalAsset $asset): bool
    {
        return $user->hasCapability(Capability::Edit) && ! $asset->state instanceof Archived;
    }

    public function replace(User $user, DigitalAsset $asset): bool
    {
        return $user->hasCapability(Capability::Replace) && ! $asset->state instanceof Archived && ! $asset->state->isProcessing();
    }

    public function delete(User $user, DigitalAsset $asset): bool
    {
        return $user->hasCapability(Capability::Delete);
    }
}
