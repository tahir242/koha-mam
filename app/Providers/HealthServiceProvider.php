<?php

namespace App\Providers;

use App\Health\KohaApiCheck;
use App\Health\MediaToolsCheck;
use App\Health\PublicationBacklogCheck;
use App\Health\StorageBackendsCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $usesRedis = config('queue.default') === 'redis' || config('cache.default') === 'redis';

        Health::checks(array_values(array_filter([
            DatabaseCheck::new(),
            CacheCheck::new(),
            $usesRedis ? RedisCheck::new() : null,
            config('queue.default') === 'redis' ? HorizonCheck::new() : null,
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(3),
            KohaApiCheck::new()->label('Koha REST API'),
            StorageBackendsCheck::new()->label('Storage backends'),
            MediaToolsCheck::new()->label('Media processing tools'),
            PublicationBacklogCheck::new()->label('Koha publication outbox'),
            app()->isProduction() ? DebugModeCheck::new() : null,
            app()->isProduction() ? EnvironmentCheck::new() : null,
        ])));
    }
}
