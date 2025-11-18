<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Logging;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Egypteam\LaravelAppLogger\Logging\Formatters\Log4jLikeFormatter;
use Egypteam\LaravelAppLogger\Logging\Transports\Cloudwatch\CloudwatchHandler;
use Illuminate\Contracts\Container\Container;
use Monolog\Handler\HandlerInterface;
use RuntimeException;

/**
 * Factory that resolves the concrete handler based on configuration.
 */
final class HandlerFactory
{
    public static function make(): HandlerInterface
    {
        $cfg      = config('app-logger');
        $provider = (string) ($cfg['provider'] ?? 'cloudwatch');

        return match ($provider) {
            'cloudwatch' => self::cloudwatch($cfg['providers']['cloudwatch'] ?? []),
            default      => throw new RuntimeException("Unknown log provider: {$provider}"),
        };
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function cloudwatch(array $c): HandlerInterface
    {
        /** @var Container|null $app */
        $app = function_exists('app') ? app() : null;

        $client = $app && $app->bound(CloudWatchLogsClient::class)
            ? $app->make(CloudWatchLogsClient::class)
            : new CloudWatchLogsClient([
                'version' => 'latest',
                'region'  => (string) ($c['region'] ?? 'us-east-1'),
            ]);

        $fallbackPath = $c['fallback_path']
            ?? (function_exists('storage_path')
                ? storage_path('logs/cloudwatch-fallback.log')
                : sys_get_temp_dir() . '/cloudwatch-fallback.log');

        $handler = new CloudwatchHandler(
            $client,
            (string) $c['group'],
            (string) $c['stream'],
            (int) ($c['batch_size'] ?? 50),
            (int) ($c['flush_seconds'] ?? 2),
            (string) $fallbackPath,
            (int) ($c['retention_days'] ?? 14),
        );

        $handler->setFormatter(new Log4jLikeFormatter());

        return $handler;
    }
}
