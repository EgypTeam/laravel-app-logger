<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Logging;

use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;

/**
 * Factory entry-point used by Laravel's "custom" log channel driver.
 *
 * In config/logging.php:
 *
 *   'channels' => [
 *       'app_log' => [
 *           'driver' => 'custom',
 *           'via'    => \Egypteam\LaravelAppLogger\Logging\AppLoggerService::class,
 *           'level'  => env('LOG_LEVEL', 'info'),
 *       ],
 *   ];
 */
final class AppLoggerService
{
    public function __invoke(array $config): Logger
    {
        $handler = HandlerFactory::make();
        $group   = new WhatFailureGroupHandler([$handler]);

        return new Logger('app_log', [$group]);
    }
}
