<?php

namespace App\Providers;

use App\Enums\Capability;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        if ($mail = config('dam.notifications.mail')) {
            Horizon::routeMailNotificationsTo($mail);
        }
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => $user?->hasCapability(Capability::ManageSettings) ?? false);
    }
}
