<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exception Handler — extended in Task 4.2 to emit structured ERROR log entries.
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Report or log an exception.
     *
     * Emits a structured ERROR log entry with exception_class, message,
     * file, line, and trace_id (from shared log context set by TraceMiddleware).
     */
    public function report(Throwable $e): void
    {
        // Skip reporting for exceptions that should not be reported
        if ($this->shouldntReport($e)) {
            return;
        }

        $traceId = '';
        try {
            $sharedContext = Log::sharedContext();
            $traceId = $sharedContext['trace_id'] ?? '';
        } catch (Throwable) {
            // Ignore if shared context is unavailable
        }

        Log::error('Unhandled exception', [
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace_id' => $traceId,
        ]);
    }
}
