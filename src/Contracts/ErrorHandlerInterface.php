<?php

declare(strict_types=1);

namespace Router\Contracts;

use Router\Request;
use Router\Response;

/**
 * Contract for a global error handler middleware.
 *
 * Implement this to customize how uncaught Throwable are formatted and sent
 * to the client (HTML error page, JSON envelope, logging, etc.).
 */
interface ErrorHandlerInterface
{
    public function handle(\Throwable $throwable, Request $request, Response $response): void;
}