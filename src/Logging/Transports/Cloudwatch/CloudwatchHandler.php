<?php

declare(strict_types=1);

namespace Egypteam\LaravelAppLogger\Logging\Transports\Cloudwatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Aws\Exception\AwsException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;

/**
 * Monolog handler that ships logs to AWS CloudWatch Logs.
 *
 * Features:
 *  - Batching (size/time-based)
 *  - Sequence token management
 *  - Simple retry/backoff
 *  - Local fallback file on repeated failures
 */
final class CloudwatchHandler extends AbstractProcessingHandler
{
    private CloudWatchLogsClient $client;

    private string $group;

    private string $stream;

    private int $batchSize;

    private int $flushSeconds;

    private string $fallbackPath;

    private int $retentionDays;

    /** @var array<int, array{timestamp:int,message:string}> */
    private array $buffer = [];

    private int $lastFlushAt = 0;

    private ?string $nextSequenceToken = null;

    private bool $initialized = false;

    public function __construct(
        CloudWatchLogsClient $client,
        string $group,
        string $stream,
        int $batchSize = 50,
        int $flushSeconds = 2,
        string $fallbackPath = '',
        int $retentionDays = 14,
        int|string|Level $level = Logger::DEBUG,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);

        $this->client        = $client;
        $this->group         = $group;
        $this->stream        = $stream;
        $this->batchSize     = max(1, $batchSize);
        $this->flushSeconds  = max(1, $flushSeconds);
        $this->fallbackPath  = $fallbackPath;
        $this->retentionDays = $retentionDays;
        $this->lastFlushAt   = time();

        // Ensure buffer is flushed when PHP shuts down
        register_shutdown_function(fn (): void => $this->flush());
    }

    protected function write(LogRecord $record): void
    {
        $this->ensureInitialized();

        $this->buffer[] = [
            'timestamp' => (int) floor((float) $record->datetime->format('U.u') * 1000),
            'message'   => (string) $record->formatted,
        ];

        if (
            count($this->buffer) >= $this->batchSize
            || (time() - $this->lastFlushAt) >= $this->flushSeconds
        ) {
            $this->flush();
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $this->client->createLogGroup(['logGroupName' => $this->group]);
        } catch (\Throwable) {
            // already exists / no permission – ignore
        }

        if ($this->retentionDays > 0) {
            try {
                $this->client->putRetentionPolicy([
                    'logGroupName'    => $this->group,
                    'retentionInDays' => $this->retentionDays,
                ]);
            } catch (\Throwable) {
            }
        }

        try {
            $this->client->createLogStream([
                'logGroupName'  => $this->group,
                'logStreamName' => $this->stream,
            ]);
        } catch (\Throwable) {
        }

        try {
            $resp = $this->client->describeLogStreams([
                'logGroupName'        => $this->group,
                'logStreamNamePrefix' => $this->stream,
            ]);

            foreach ($resp['logStreams'] ?? [] as $ls) {
                if (($ls['logStreamName'] ?? '') === $this->stream) {
                    $this->nextSequenceToken = $ls['uploadSequenceToken'] ?? null;
                    break;
                }
            }
        } catch (\Throwable) {
        }

        $this->initialized = true;
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            $this->lastFlushAt = time();

            return;
        }

        usort(
            $this->buffer,
            static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']
        );

        $events            = $this->buffer;
        $this->buffer      = [];
        $this->lastFlushAt = time();

        $payload = [
            'logGroupName'  => $this->group,
            'logStreamName' => $this->stream,
            'logEvents'     => $events,
        ];

        if ($this->nextSequenceToken !== null) {
            $payload['sequenceToken'] = $this->nextSequenceToken;
        }

        $attempts = 0;
        $max      = 3;

        do {
            try {
                $result                 = $this->client->putLogEvents($payload);
                $this->nextSequenceToken = $result['nextSequenceToken'] ?? null;

                return;
            } catch (AwsException $e) {
                $attempts++;
                $code = (string) $e->getAwsErrorCode();

                if (in_array($code, ['InvalidSequenceTokenException', 'DataAlreadyAcceptedException'], true)) {
                    $this->refreshSequenceToken();
                    if ($this->nextSequenceToken !== null) {
                        $payload['sequenceToken'] = $this->nextSequenceToken;
                    } else {
                        unset($payload['sequenceToken']);
                    }
                } else {
                    usleep(200_000 * $attempts);
                }
            } catch (\Throwable) {
                $attempts++;
                usleep(200_000 * $attempts);
            }
        } while ($attempts < $max);

        $this->writeFallback($events);
    }

    private function refreshSequenceToken(): void
    {
        try {
            $resp = $this->client->describeLogStreams([
                'logGroupName'        => $this->group,
                'logStreamNamePrefix' => $this->stream,
            ]);

            foreach ($resp['logStreams'] ?? [] as $ls) {
                if (($ls['logStreamName'] ?? '') === $this->stream) {
                    $this->nextSequenceToken = $ls['uploadSequenceToken'] ?? null;

                    return;
                }
            }
        } catch (\Throwable) {
        }

        $this->nextSequenceToken = null;
    }

    /**
     * @param array<int, array{timestamp:int,message:string}> $events
     */
    private function writeFallback(array $events): void
    {
        if ($this->fallbackPath === '') {
            return;
        }

        try {
            $dir = dirname($this->fallbackPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $fh = @fopen($this->fallbackPath, 'ab');
            if (! $fh) {
                return;
            }

            foreach ($events as $event) {
                $line = '[' . date('c', (int) floor($event['timestamp'] / 1000)) . '] ' . $event['message'];
                fwrite($fh, $line);
            }

            fclose($fh);
        } catch (\Throwable) {
            // final safety net: swallow all errors
        }
    }
}
