# Egypteam Laravel App Logger

A structured, provider-agnostic logging package for Laravel inspired by Log4j patterns.  
Supports AWS CloudWatch out of the box and exposes a clean application-level logging API via the `AppLog` facade.

## Features

- Log4j-like API: `trace`, `debug`, `info`, `warn`, `error`, `fatal`
- Structured JSON logs (ready for log analytics)
- MDC (Mapped Diagnostic Context) and NDC (Nested Diagnostic Context)
- CloudWatch transport with batching, sequence token recovery, and fallback
- Provider-agnostic architecture (easy to plug other backends later)
- Laravel auto-discovery (service provider + facade)
- Compatible with Laravel 10 / 11 / 12 and Monolog 3

---

## Installation

Install via Composer:

```bash
composer require egypteam/laravel-app-logger
```

The package uses Laravel auto-discovery, so the service provider and facade will be registered automatically.

If you want to publish the configuration file:

```bash
php artisan vendor:publish --provider="Egypteam\LaravelAppLogger\LaravelAppLoggerServiceProvider" --tag=config
```

This will create `config/app-logger.php` in your application.

---

## Configuration

### 1. Logging Channel

In your application's `config/logging.php`, add the `app_log` channel:

```php
'channels' => [
    // ...

    'app_log' => [
        'driver' => 'custom',
        'via'    => \Egypteam\LaravelAppLogger\Logging\AppLoggerService::class,
        'level'  => env('LOG_LEVEL', 'info'),
    ],
],
```

> You can keep your default Laravel channels intact. `app_log` is an additional application-level channel for structured logs.

### 2. Environment Variables

The core behavior can be controlled via `.env`:

```dotenv
APP_LOG_PROVIDER=cloudwatch

# CloudWatch settings
AWS_REGION=us-east-1
CW_LOG_GROUP=my-app
CW_LOG_STREAM=web
CW_RETENTION_DAYS=14
CW_BATCH_SIZE=50
CW_FLUSH_SECONDS=2
CW_FALLBACK_PATH=/var/log/laravel/cloudwatch-fallback.log

# Sampling (debug)
APP_LOG_SAMPLING_DEBUG=0.10   # 10% of debug logs kept
```

### 3. Config File (`config/app-logger.php`)

If published, you will see:

- `provider` – active backend (currently `cloudwatch`)
- `sampling` – per-level sampling factors
- `providers.cloudwatch` – CloudWatch-specific configuration

The `fallback_path` can be `null` (default) and will fall back to `storage/logs/cloudwatch-fallback.log` internally.

---

## Usage

### Basic Logging

```php
use Egypteam\LaravelAppLogger\Facades\AppLog;

AppLog::info('User logged in', ['user_id' => 42]);

AppLog::warn('High latency detected', [
    'endpoint' => '/api/orders',
    'duration_ms' => 3500,
]);

AppLog::error('Payment failed', [
    'order_id' => 123,
    'gateway'  => 'stripe',
]);
```

### Using MDC (Context Map) and NDC (Context Stack)

```php
use Egypteam\LaravelAppLogger\Facades\AppLog;

AppLog::withContext(['tenant' => 'acme'])
    ->pushContext('checkout')
    ->info('Order processed', ['order_id' => 123]);

AppLog::pushContext('payment')
    ->debug('Payment attempt', ['amount' => 99.90]);
```

MDC appears as the `mdc` field in JSON, NDC as `ndc` (array of scopes).

### Sampling

Only the `debug` level is sampled by default:

```dotenv
APP_LOG_SAMPLING_DEBUG=0.05   # keep ~5% of debug logs
```

Higher levels (`info`, `warning`, `error`, etc.) default to `1.0` (100% always logged).

---

## Output Format

Logs are sent as JSON lines. Example:

```json
{
  "ts": "2025-11-05T13:44:10+00:00",
  "level": "INFO",
  "message": "Order processed",
  "channel": "app_log",
  "env": "production",
  "app": "ExampleApp",
  "host": "web-01",
  "ip": "10.0.1.25",
  "request": {
    "method": "POST",
    "uri": "/api/orders",
    "id": "req-abc123"
  },
  "user_id": 42,
  "mdc": { "tenant": "acme" },
  "ndc": ["checkout"],
  "context": { "order_id": 123 }
}
```

This format is ideal for CloudWatch Logs Insights and other log-analytics engines.

---

## CloudWatch Transport

The `CloudwatchHandler` is responsible for actually sending events to AWS:

- Buffers events and sends in batches (`CW_BATCH_SIZE` & `CW_FLUSH_SECONDS`)
- Handles CloudWatch sequence tokens (`InvalidSequenceTokenException`, `DataAlreadyAcceptedException`)
- Retries with simple backoff on transient errors
- Falls back to a local file when all retries fail (`CW_FALLBACK_PATH`)

You just configure the `.env` and use `AppLog` — all complexity is contained inside the package.

---

## Extending to Other Providers

The architecture is transport-agnostic. To support a new provider (e.g., Datadog, Loki, a file-based handler):

1. Create a new handler class under:

   ```text
   src/Logging/Transports/MyProvider/MyProviderHandler.php
   ```

   Implement it as a Monolog handler (`extends AbstractProcessingHandler` or implements `HandlerInterface`).

2. Update `HandlerFactory::make()` to add a new case:

   ```php
   return match ($provider) {
       'cloudwatch' => self::cloudwatch(...),
       'myprovider' => self::myProvider(...),
       default      => throw new RuntimeException(...),
   };
   ```

3. Add the provider config in `config/app-logger.php` under `'providers'`.

4. Set in `.env`:

   ```dotenv
   APP_LOG_PROVIDER=myprovider
   ```

Your application code continues to call `AppLog::info(...)` etc. with no changes.

---

## Testing

The package is designed to be test-friendly:

- You can bind a mock `CloudWatchLogsClient` into the container.
- `HandlerFactory` will prefer the bound instance instead of creating a new one.
- Use `orchestra/testbench` to run integration tests with a full Laravel container.

Basic test flows:

- Unit tests for `Log4jLikeFormatter` (JSON structure).
- Unit tests for `CloudwatchHandler` (batching, sequence token, fallback).
- Feature tests for `AppLog` facade and the `app_log` channel wiring.

---

## Requirements

- PHP 8.2+
- Laravel 10, 11 or newer (tested with 10/11)
- AWS credentials with permissions for:
  - `logs:CreateLogGroup`
  - `logs:CreateLogStream`
  - `logs:PutLogEvents`
  - `logs:DescribeLogStreams`

---

## License

Licensed under the MIT License.

© EgypTeam / Pedro Ferreira
