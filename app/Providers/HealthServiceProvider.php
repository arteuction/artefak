<?php

declare(strict_types=1);

namespace App\Providers;

use App\Checks\FailedDomainEventsCheck;
use App\Checks\OutboxBacklogCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Registers Spatie Health checks.
 *
 * Deferred to `booted` so Spatie's own service provider has finished
 * binding the Health service before we call Health::checks().
 */
final class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            Health::checks([
                DatabaseCheck::new(),
                DebugModeCheck::new(),
                EnvironmentCheck::new()->expectEnvironment('production'),
                OptimizedAppCheck::new()->if(app()->environment('production')),
                UsedDiskSpaceCheck::new()->failWhenUsedSpaceIsAbovePercentage(90),
                OutboxBacklogCheck::new(),
                FailedDomainEventsCheck::new(),
            ]);
        });
    }
}
