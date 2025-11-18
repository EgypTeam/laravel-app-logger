<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Facades;

use Egypteam\LaravelAppLogger\Support\AppLogger;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void trace(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void info(string $message, array $context = [])
 * @method static void warn(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void fatal(string $message, array $context = [])
 * @method static AppLogger withContext(array $kv)
 * @method static AppLogger pushContext(string $value)
 * @method static AppLogger popContext()
 */
final class AppLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'egyp.app-logger';
    }
}
