<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Logging\Formatters;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formats records into structured JSON inspired by Log4j layouts.
 */
final class Log4jLikeFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $context = $record->context ?? [];

        $payload = [
            'ts'      => $record->datetime->format('c'),
            'level'   => $record->level->getName(),
            'message' => $record->message,
            'channel' => $record->channel,
            'env'     => config('app.env'),
            'app'     => config('app.name'),
            'host'    => gethostname() ?: null,
            'ip'      => request()?->ip(),
            'request' => [
                'method' => request()?->method(),
                'uri'    => request()?->getRequestUri(),
                'id'     => request()?->header('X-Request-Id'),
            ],
            'user_id' => auth()->id(),
            'mdc'     => $context['_mdc'] ?? null,
            'ndc'     => $context['_ndc'] ?? null,
            'context' => array_diff_key(
                $context,
                ['_mdc' => 1, '_ndc' => 1, '_level_alias' => 1]
            ),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * @param array<int, LogRecord> $records
     */
    public function formatBatch(array $records): string
    {
        $out = '';

        foreach ($records as $record) {
            $out .= $this->format($record);
        }

        return $out;
    }
}
