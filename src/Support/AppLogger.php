<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * High-level, provider-agnostic logger with a Log4j-like API.
 *
 * Wraps a dedicated Laravel log channel ("app_log") and handles:
 *  - MDC (mapped diagnostic context)
 *  - NDC (nested diagnostic context)
 *  - Sampling for noisy levels (e.g., debug)
 */
final class AppLogger
{
    /** @var array<string, mixed> */
    private array $mdc = [];

    /** @var string[] */
    private array $ndc = [];

    private function channel(): LoggerInterface
    {
        /** @var LoggerInterface $logger */
        $logger = Log::channel('app_log');

        return $logger;
    }

    public function withContext(array $kv): self
    {
        $clone = clone $this;

        foreach ($kv as $key => $value) {
            $clone->mdc[(string) $key] = $value;
        }

        return $clone;
    }

    public function pushContext(string $value): self
    {
        $clone = clone $this;
        $clone->ndc[] = $value;

        return $clone;
    }

    public function popContext(): self
    {
        $clone = clone $this;
        array_pop($clone->ndc);

        return $clone;
    }

    public function trace(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context + ['_level_alias' => 'TRACE']);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function fatal(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context + ['_level_alias' => 'FATAL']);
    }

    private function log(string $level, string $message, array $context): void
    {
        $merged = $context + [
            '_mdc' => $this->mdc,
            '_ndc' => $this->ndc,
        ];

        $sampling = (float) config("app-logger.sampling.$level", 1.0);

        if ($level === 'debug' && $sampling < 1.0) {
            if (mt_rand() / mt_getrandmax() > $sampling) {
                return;
            }
        }

        $this->channel()->log($level, $message, $merged);
    }
}
