<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [
    /*
     * Active provider (transport).
     * Supported out of the box: "cloudwatch"
     */
    'provider' => Env::get('APP_LOG_PROVIDER', 'cloudwatch'),

    /*
     * Per-level sampling.
     * debug can be throttled in production, others default to 100%.
     */
    'sampling' => [
        'debug'     => (float) Env::get('APP_LOG_SAMPLING_DEBUG', 0.10),
        'info'      => 1.0,
        'notice'    => 1.0,
        'warning'   => 1.0,
        'error'     => 1.0,
        'critical'  => 1.0,
        'alert'     => 1.0,
        'emergency' => 1.0,
    ],

    /*
     * Provider specific settings.
     */
    'providers' => [
        'cloudwatch' => [
            'group'          => Env::get('CW_LOG_GROUP', 'my-app'),
            'stream'         => Env::get('CW_LOG_STREAM', 'web'),
            'region'         => Env::get('AWS_REGION', 'us-east-1'),
            'retention_days' => (int) Env::get('CW_RETENTION_DAYS', 14),
            'batch_size'     => (int) Env::get('CW_BATCH_SIZE', 50),
            'flush_seconds'  => (int) Env::get('CW_FLUSH_SECONDS', 2),
            // Null here: real default computed in HandlerFactory using storage_path()
            'fallback_path'  => Env::get('CW_FALLBACK_PATH') ?: null,
        ],
    ],
];
