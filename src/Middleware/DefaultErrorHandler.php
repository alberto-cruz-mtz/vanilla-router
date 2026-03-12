<?php

declare(strict_types=1);

namespace Router\Middleware;

use Router\Contracts\ErrorHandlerInterface;
use Router\Request;
use Router\Response;

/**
 * Default error handler shipped with the router.
 *
 * - Responds with JSON when the client expects it (XHR or Accept: application/json).
 * - Falls back to a minimal HTML error page otherwise.
 * - Hides stack traces in production (when APP_DEBUG env var is not "true").
 */
final class DefaultErrorHandler implements ErrorHandlerInterface
{
    public function handle(\Throwable $throwable, Request $request, Response $response): void
    {
        $statusCode = $this->resolveStatusCode($throwable);
        $debug      = $this->isDebugMode();

        if ($this->clientExpectsJson($request)) {
            $this->sendJsonError($response, $throwable, $statusCode, $debug);
            return;
        }

        $this->sendHtmlError($response, $throwable, $statusCode, $debug);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function resolveStatusCode(\Throwable $throwable): int
    {
        if ($throwable instanceof \Router\Exceptions\HttpException) {
            return $throwable->getStatusCode();
        }

        return 500;
    }

    private function clientExpectsJson(Request $request): bool
    {
        return $request->isJson() || $request->isXhr();
    }

    private function isDebugMode(): bool
    {
        return filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
    }

    private function sendJsonError(
        Response   $response,
        \Throwable $throwable,
        int        $statusCode,
        bool       $debug,
    ): void {
        $payload = [
            'error'   => true,
            'message' => $throwable->getMessage() ?: 'An unexpected error occurred.',
        ];

        if ($debug) {
            $payload['exception'] = get_class($throwable);
            $payload['file']      = $throwable->getFile();
            $payload['line']      = $throwable->getLine();
            $payload['trace']     = $throwable->getTraceAsString();
        }

        $response->json($payload, $statusCode);
    }

    private function sendHtmlError(
        Response   $response,
        \Throwable $throwable,
        int        $statusCode,
        bool       $debug,
    ): void {
        $title   = $this->statusTitle($statusCode);
        $message = htmlspecialchars($throwable->getMessage() ?: 'An unexpected error occurred.', ENT_QUOTES, 'UTF-8');
        $trace   = '';

        if ($debug) {
            $rawTrace = htmlspecialchars($throwable->getTraceAsString(), ENT_QUOTES, 'UTF-8');
            $file     = htmlspecialchars($throwable->getFile(), ENT_QUOTES, 'UTF-8');
            $line     = $throwable->getLine();
            $class    = htmlspecialchars(get_class($throwable), ENT_QUOTES, 'UTF-8');
            $trace    = <<<HTML
                <section class="trace">
                    <h2>Debug Info</h2>
                    <p><strong>Exception:</strong> {$class}</p>
                    <p><strong>File:</strong> {$file} (line {$line})</p>
                    <pre>{$rawTrace}</pre>
                </section>
            HTML;
        }

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>{$statusCode} — {$title}</title>
                <style>
                    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
                    body { font-family: system-ui, sans-serif; background: #f8f9fa; color: #212529; display: grid; place-items: center; min-height: 100vh; padding: 2rem; }
                    .card { background: #fff; border-radius: .75rem; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 2.5rem 3rem; max-width: 680px; width: 100%; }
                    h1 { font-size: 2rem; color: #dc3545; margin-bottom: .5rem; }
                    p { line-height: 1.6; color: #495057; margin-top: .75rem; }
                    .trace { margin-top: 2rem; border-top: 1px solid #dee2e6; padding-top: 1.5rem; }
                    .trace h2 { font-size: 1rem; text-transform: uppercase; letter-spacing: .05em; color: #6c757d; margin-bottom: 1rem; }
                    pre { background: #f1f3f5; padding: 1rem; border-radius: .5rem; overflow-x: auto; font-size: .8rem; line-height: 1.5; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>{$statusCode} — {$title}</h1>
                    <p>{$message}</p>
                    {$trace}
                </div>
            </body>
            </html>
        HTML;

        $response->html($html, $statusCode);
    }

    private function statusTitle(int $code): string
    {
        return match ($code) {
            400     => 'Bad Request',
            401     => 'Unauthorized',
            403     => 'Forbidden',
            404     => 'Not Found',
            405     => 'Method Not Allowed',
            422     => 'Unprocessable Entity',
            429     => 'Too Many Requests',
            500     => 'Internal Server Error',
            default => 'Error',
        };
    }
}