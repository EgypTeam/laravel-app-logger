<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger;

use Egypteam\LaravelAppLogger\Support\AppLogger;
use Illuminate\Support\ServiceProvider;

final class LaravelAppLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/app-logger.php', 'app-logger');

        $this->app->singleton('egyp.app-logger', static fn () => new AppLogger());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/app-logger.php' => config_path('app-logger.php'),
        ], 'config');
    }
}
