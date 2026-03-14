<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * JsonLogFormatter
 *
 * Extends Monolog's JsonFormatter to produce structured log entries that
 * include the required observability fields:
 *   timestamp, level, message, service, environment, trace_id, span_id
 *
 * The trace_id and span_id are injected into the Monolog shared context by
 * TraceMiddleware after the OTel root span is started, enabling log-to-trace
 * correlation in Grafana.
 *
 * Full implementation in Task 4.2. This stub ensures the logging channel
 * can be configured and the application boots without errors.
 */
class JsonLogFormatter extends JsonFormatter
{
    public function __construct()
    {
        parent::__construct(
            batchMode: self::BATCH_MODE_NEWLINES,
            appendNewline: true,
            ignoreEmptyContextAndExtra: false,
            includeStacktraces: false,
        );
    }

    /**
     * Format a log record into a structured JSON string.
     */
    public function format(LogRecord $record): string
    {
        $data = [
            'timestamp'   => $record->datetime->format(\DateTimeInterface::ATOM),
            'level'       => $record->level->getName(),
            'message'     => $record->message,
            'service'     => config('app.name', 'laravel-observability-demo'),
            'environment' => config('app.env', 'local'),
            'trace_id'    => $record->context['trace_id'] ?? ($record->extra['trace_id'] ?? ''),
            'span_id'     => $record->context['span_id'] ?? ($record->extra['span_id'] ?? ''),
        ];

        // Merge any additional context fields (method, uri, status_code, etc.)
        $context = $record->context;
        unset($context['trace_id'], $context['span_id']);

        if (! empty($context)) {
            $data = array_merge($data, $context);
        }

        return $this->toJson($data, true).($this->appendNewline ? "\n" : '');
    }
}
