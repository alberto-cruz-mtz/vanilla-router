<?php

declare(strict_types=1);

namespace Router\Contracts;

use Router\Request;
use Router\Response;

/**
 * Contract for route or global middlewares.
 *
 * A middleware may short-circuit the chain by sending a response and returning
 * without calling $next, or pass control forward by invoking $next($request).
 */
interface MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void;
}