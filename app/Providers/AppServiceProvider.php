<?php

namespace App\Providers;

use App\Auth\Sso\CodeExchangeAdapter;
use App\Auth\Sso\KohaSsoAdapter;
use App\Enums\Capability;
use App\Http\Middleware\EnsureStaffSession;
use App\Koha\KohaClient;
use App\Media\MediaTypeRegistry;
use App\Media\Scanning\ClamAvScanner;
use App\Media\Scanning\MalwareScanner;
use App\Media\Scanning\NullScanner;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Policies\DigitalAssetPolicy;
use App\Settings\Settings;
use App\Storage\StorageManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KohaClient::class, fn () => KohaClient::fromConfig());
        $this->app->singleton(MediaTypeRegistry::class, fn () => MediaTypeRegistry::fromConfig());
        $this->app->singleton(StorageManager::class);
        $this->app->singleton(Settings::class);

        $this->app->bind(KohaSsoAdapter::class, fn () => new CodeExchangeAdapter(config('dam.sso'), (string) config('dam.koha.instance_id'), config('dam.koha')));

        $this->app->bind(MalwareScanner::class, fn ($app) => match (config('dam.scanning.driver')) {
            'clamav' => $app->make(ClamAvScanner::class),
            default => new NullScanner,
        });
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        foreach (Capability::cases() as $capability) {
            Gate::define($capability->value, fn (User $user) => $user->hasCapability($capability));
        }

        Gate::policy(DigitalAsset::class, DigitalAssetPolicy::class);

        // Re-run the staff session checks on every Livewire action request.
        Livewire::addPersistentMiddleware([EnsureStaffSession::class]);

        RateLimiter::for('sso', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(config('dam.upload.per_minute'))->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('viewer', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('views', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('media', fn (Request $request) => Limit::perMinute(config('dam.delivery.requests_per_minute'))->by($request->ip()));

        try {
            $this->app->make(Settings::class)->apply();
        } catch (Throwable) {
            // Settings table not migrated yet (fresh install / migrations running).
        }
    }
}
