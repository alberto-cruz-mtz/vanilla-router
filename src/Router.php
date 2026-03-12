<?php
declare(strict_types=1);

namespace Router;

use InvalidArgumentException;
use Router\Contracts\ErrorHandlerInterface;
use Router\Contracts\MiddlewareInterface;
use Router\Exceptions\HttpException;
use Router\Middleware\DefaultErrorHandler;
use Throwable;

/**
 * Express-inspired HTTP router for PHP.
 *
 * Basic usage:
 *
 *   $router = new Router();
 *
 *   // Closure handler
 *   $router->get('/ping', function (Request $req, Response $res): void {
 *       $res->json(['pong' => true]);
 *   });
 *
 *   // Class method handler
 *   $router->get('/users/:id', [UserController::class, 'show']);
 *   $router->post('/users',    [UserController::class, 'store']);
 *
 *   // Global middleware
 *   $router->use(new AuthMiddleware());
 *
 *   // Per-route middleware
 *   $router->put('/users/:id', [UserController::class, 'update'], [new AdminOnlyMiddleware()]);
 *
 *   // Custom error handler
 *   $router->setErrorHandler(new MyErrorHandler());
 *
 *   $router->dispatch();
 */
final class Router
{
    /** @var Route[] All registered routes. */
    private array $routes = [];

    /** @var MiddlewareInterface[] Middlewares applied to every matched request. */
    private array $globalMiddlewares = [];

    private ErrorHandlerInterface $errorHandler;

    public function __construct()
    {
        $this->errorHandler = new DefaultErrorHandler();
    }

    // ─── Route registration ───────────────────────────────────────────────────

    /**
     * Registers a GET route.
     *
     * @param callable|array{class-string, string} $handler
     * @param MiddlewareInterface[] $middlewares Per-route middlewares.
     */
    public function get(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /** Registers a POST route. */
    public function post(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    /** Registers a PUT route. */
    public function put(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    /** Registers a PATCH route. */
    public function patch(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middlewares);
    }

    /** Registers a DELETE route. */
    public function delete(string $path, callable|array $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    /** Registers a route that responds to every HTTP verb. */
    public function any(string $path, callable|array $handler, array $middlewares = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method) {
            $this->addRoute($method, $path, $handler, $middlewares);
        }

        return $this;
    }

    // ─── Middleware & error handler ───────────────────────────────────────────

    /** Appends a global middleware that runs on every matched request. */
    public function use(MiddlewareInterface $middleware): self
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /** Replaces the built-in error handler with a custom implementation. */
    public function setErrorHandler(ErrorHandlerInterface $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    // ─── Dispatching ──────────────────────────────────────────────────────────

    /**
     * Reads the current request from PHP superglobals and dispatches it.
     * This is the main entry point in production.
     */
    public function dispatch(): void
    {
        $this->run(Request::fromGlobals(), new Response());
    }

    /**
     * Dispatches a pre-built Request object.
     * Useful for unit / integration testing.
     */
    public function run(Request $request, Response $response): void
    {
        try {
            $route = $this->resolveRoute($request);
            $enrichedRequest = $request->withRouteParams($route->extractParams($request->getPath()));

            $this->runMiddlewarePipeline($enrichedRequest, $response, $route);
        } catch (Throwable $throwable) {
            $this->dispatchToErrorHandler($throwable, $request, $response);
        }
    }

    // ─── Private — routing ────────────────────────────────────────────────────

    private function addRoute(
        string         $method,
        string         $path,
        callable|array $handler,
        array          $middlewares,
    ): self
    {
        $this->routes[] = new Route(
            method: $method,
            path: $path,
            handler: $handler,
            middlewares: $middlewares,
        );

        return $this;
    }

    /**
     * Finds the route that matches both path and HTTP method.
     *
     * @throws HttpException 404 — path not registered.
     * @throws HttpException 405 — path registered, but not for this method.
     */
    private function resolveRoute(Request $request): Route
    {
        $methodMatch = null;
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!$this->routePathMatches($route, $request->getPath())) {
                continue;
            }

            $pathMatched = true;

            if ($route->matches($request->getMethod(), $request->getPath())) {
                $methodMatch = $route;
                break;
            }
        }

        if (!$pathMatched) {
            throw HttpException::notFound("No route registered for path: {$request->getPath()}");
        }

        if ($methodMatch === null) {
            $allowed = $this->allowedMethodsForPath($request->getPath());
            throw HttpException::methodNotAllowed(
                "Method {$request->getMethod()} is not allowed on {$request->getPath()}. "
                . 'Allowed: ' . implode(', ', $allowed)
            );
        }

        return $methodMatch;
    }

    /**
     * Checks whether a route's path pattern matches the request path,
     * independent of the HTTP method.
     */
    private function routePathMatches(Route $route, string $requestPath): bool
    {
        $probe = new Route('GET', $route->getPath(), static fn() => null);
        return $probe->matches('GET', $requestPath);
    }

    /** @return string[] */
    private function allowedMethodsForPath(string $path): array
    {
        $methods = [];

        foreach ($this->routes as $route) {
            if ($this->routePathMatches($route, $path)) {
                $methods[] = $route->getMethod();
            }
        }

        return array_unique($methods);
    }

    // ─── Private — middleware pipeline ────────────────────────────────────────

    /**
     * Merges global and per-route middlewares, then runs the resulting pipeline
     * followed by the route handler.
     */
    private function runMiddlewarePipeline(Request $request, Response $response, Route $route): void
    {
        $allMiddlewares = array_merge($this->globalMiddlewares, $route->getMiddlewares());
        $handler = $this->resolveHandler($route->getHandler());
        $pipeline = $this->buildPipeline($allMiddlewares, $handler);

        $pipeline($request, $response);
    }

    /**
     * Recursively wraps middlewares into a single callable chain.
     * The innermost callable is the route handler itself.
     */
    private function buildPipeline(array $middlewares, callable $coreHandler): callable
    {
        if (empty($middlewares)) {
            return $coreHandler;
        }

        $middleware = array_shift($middlewares);
        $downstream = $this->buildPipeline($middlewares, $coreHandler);

        return static function (Request $request, Response $response) use ($middleware, $downstream): void {
            $middleware->handle(
                $request,
                $response,
                static fn(Request $updatedRequest) => $downstream($updatedRequest, $response),
            );
        };
    }

    /**
     * Normalizes a handler definition into a plain PHP callable.
     *
     * Accepts:
     *   - Any native callable (Closure, function name, invokable object, …)
     *   - [ClassName::class, 'methodName']  — class is instantiated automatically
     *
     * @throws InvalidArgumentException When the handler cannot be resolved.
     */
    private function resolveHandler(mixed $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        if (!is_array($handler) || count($handler) !== 2) {
            throw new InvalidArgumentException(
                'Route handler must be a callable or [ClassName::class, "methodName"].'
            );
        }

        [$class, $method] = $handler;

        $this->assertClassExists($class);

        $instance = new $class();

        $this->assertMethodExists($instance, $method, $class);

        return [$instance, $method];
    }

    /** @throws InvalidArgumentException */
    private function assertClassExists(string $class): void
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Handler class not found: {$class}");
        }
    }

    /** @throws InvalidArgumentException */
    private function assertMethodExists(object $instance, string $method, string $class): void
    {
        if (!method_exists($instance, $method)) {
            throw new InvalidArgumentException("Handler method not found: {$class}::{$method}");
        }
    }

    // ─── Private — error handling ─────────────────────────────────────────────

    private function dispatchToErrorHandler(
        Throwable $throwable,
        Request   $request,
        Response  $response,
    ): void
    {
        if ($response->isSent()) {
            return;
        }

        try {
            $this->errorHandler->handle($throwable, $request, $response);
        } catch (Throwable) {
            if (!$response->isSent()) {
                http_response_code(500);
                echo 'A critical error occurred and the error handler itself failed.';
            }
        }
    }
}