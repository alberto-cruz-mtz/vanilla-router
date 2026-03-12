# vanilla-router — Documentation

A lightweight, zero-dependency HTTP router for PHP 8.1+, inspired by Express.js.

> [Documentación en Español](../es/README.md)

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Router](#router)
  - [Route Registration](#route-registration)
  - [Route Parameters](#route-parameters)
  - [Global Middleware](#global-middleware)
  - [Error Handling](#error-handling)
  - [Dispatching](#dispatching)
- [Request](#request)
- [Response](#response)
- [Middleware](#middleware)
- [HttpException](#httpexception)
- [Architecture](#architecture)

---

## Requirements

- PHP >= 8.1

## Installation

```bash
composer require alberto-cruz-mtz/vanilla-router
```

---

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Router\Router;
use Router\Request;
use Router\Response;

$router = new Router();

$router->get('/', static function (Request $req, Response $res): void {
    $res->html('<h1>Hello, World!</h1>');
});

$router->get('/users/:id', static function (Request $req, Response $res): void {
    $id = $req->param('id');
    $res->json(['id' => $id, 'name' => 'John Doe']);
});

$router->post('/users', static function (Request $req, Response $res): void {
    $name = $req->body('name');
    $res->json(['created' => true, 'name' => $name], 201);
});

$router->dispatch();
```

---

## Router

`Router\Router` is the main entry point. It registers routes, attaches middleware, and dispatches
incoming HTTP requests through the full middleware pipeline.

### Route Registration

All registration methods return `self`, enabling fluent chaining.

| Method                                                           | HTTP Verb(s)                                          |
| ---------------------------------------------------------------- | ----------------------------------------------------- |
| `get(string $path, callable\|array $handler, array $mw = [])`   | GET                                                   |
| `post(string $path, callable\|array $handler, array $mw = [])`  | POST                                                  |
| `put(string $path, callable\|array $handler, array $mw = [])`   | PUT                                                   |
| `patch(string $path, callable\|array $handler, array $mw = [])` | PATCH                                                 |
| `delete(string $path, callable\|array $handler, array $mw = [])` | DELETE                                               |
| `any(string $path, callable\|array $handler, array $mw = [])`   | GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD          |

**Closure handler:**

```php
$router->get('/hello', static function (Request $req, Response $res): void {
    $res->text('Hello!');
});
```

**Class-based handler** (invokable or `[ClassName::class, 'method']`):

```php
// Invokable class
class HomeController {
    public function __invoke(Request $req, Response $res): void {
        $res->html('<h1>Home</h1>');
    }
}

$router->get('/', HomeController::class);

// Static method reference
class UserController {
    public function show(Request $req, Response $res): void {
        $res->json(['id' => $req->param('id')]);
    }
}

$router->get('/users/:id', [UserController::class, 'show']);
```

**Chaining:**

```php
$router
    ->get('/users',      [UserController::class, 'index'])
    ->post('/users',     [UserController::class, 'store'])
    ->get('/users/:id',  [UserController::class, 'show'])
    ->put('/users/:id',  [UserController::class, 'update'])
    ->delete('/users/:id', [UserController::class, 'destroy']);
```

### Route Parameters

Define dynamic segments using the `:param` syntax. Values are available via `$req->param()`.

```php
$router->get('/posts/:slug/comments/:id', static function (Request $req, Response $res): void {
    $slug = $req->param('slug');
    $id   = $req->param('id');
    $res->json(['slug' => $slug, 'commentId' => $id]);
});
```

### Global Middleware

Use `Router::use()` to attach middleware that runs on every matched request.

```php
use Router\Contracts\MiddlewareInterface;
use Router\Request;
use Router\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        $token = $request->header('authorization');

        if ($token === null) {
            throw \Router\Exceptions\HttpException::unauthorized();
        }

        $next($request, $response);
    }
}

$router->use(new AuthMiddleware());
```

Per-route middleware is passed as the third argument to any registration method:

```php
$router->get('/admin', [AdminController::class, 'index'], [new AuthMiddleware()]);
```

### Error Handling

By default, uncaught exceptions are handled by `DefaultErrorHandler`, which detects whether to
respond in JSON or HTML and hides stack traces unless `APP_DEBUG=true`.

To override, implement `ErrorHandlerInterface` and call `setErrorHandler()`:

```php
use Router\Contracts\ErrorHandlerInterface;
use Router\Request;
use Router\Response;

final class MyErrorHandler implements ErrorHandlerInterface
{
    public function handle(\Throwable $throwable, Request $request, Response $response): void
    {
        $response->json([
            'error'   => true,
            'message' => $throwable->getMessage(),
        ], 500);
    }
}

$router->setErrorHandler(new MyErrorHandler());
```

### Dispatching

```php
// Production: reads from PHP superglobals automatically
$router->dispatch();

// Testing: pass a pre-built Request/Response pair
$request  = Request::fromGlobals();
$response = new Response();
$router->run($request, $response);
```

---

## Request

`Router\Request` is an immutable wrapper over PHP superglobals. Build it with the static factory:

```php
$request = Request::fromGlobals();
```

### Methods

#### HTTP info

```php
$request->getMethod(); // 'GET', 'POST', ...
$request->getPath();   // '/users/42'
```

#### Query string (`$_GET`)

```php
$request->query('page', 1);   // single value, sanitized; default = 1
$request->allQuery();          // all query params, sanitized
```

#### Body (`$_POST`)

```php
$request->body('email');       // single value, sanitized
$request->allBody();           // all body params, sanitized
```

#### Route parameters (`:param`)

```php
$request->param('id');         // value extracted from the URL pattern
$request->allParams();         // all route params as array
```

#### Headers

```php
$request->header('content-type');            // case-insensitive lookup
$request->header('x-api-key', 'fallback');   // with default
```

#### Files

```php
$request->getFiles(); // raw $_FILES array
```

#### JSON body

```php
if ($request->isJson()) {
    $data = $request->json(); // decoded array; throws \JsonException on invalid JSON
}
```

#### XHR detection

```php
$request->isXhr(); // true when X-Requested-With: XMLHttpRequest
```

> **Note:** `query()`, `body()`, and `param()` sanitize string values with
> `htmlspecialchars(trim(...), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` and recurse into arrays.

---

## Response

`Router\Response` is a fluent builder that collects status, headers, and body before emitting
everything at once via `send()`. All response body methods call `send()` internally.

### Status

```php
$response->status(201);       // returns self for chaining
$response->getStatusCode();   // int
```

### Headers

```php
$response->withHeader('X-Custom', 'value');                  // single header
$response->withHeaders(['X-Foo' => 'a', 'X-Bar' => 'b']);   // multiple headers
```

### Body helpers

```php
// HTML
$response->html('<h1>Hello</h1>');
$response->html('<h1>Created</h1>', 201);

// PHP view file
$response->view('/path/to/view.php', ['name' => 'Alice']);

// JSON
$response->json(['key' => 'value']);
$response->json(['key' => 'value'], 201);

// JSON error envelope  { error: true, message: '...', details: ... }
$response->jsonError('Validation failed', 422, ['field' => 'email']);

// Plain text
$response->text('OK');

// Redirect
$response->redirect('/login');
$response->redirect('/dashboard', 301);
```

### Manual emit

```php
$response
    ->status(200)
    ->withHeader('Content-Type', 'text/plain')
    ->send(); // idempotent — calling send() a second time is a no-op
```

---

## Middleware

Implement `Router\Contracts\MiddlewareInterface`:

```php
interface MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void;
}
```

Call `$next($request, $response)` to continue the chain. Omitting the call short-circuits and
prevents the route handler from executing.

**Example — request logger:**

```php
final class LoggerMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        error_log($request->getMethod() . ' ' . $request->getPath());
        $next($request, $response);
    }
}
```

**Example — CORS:**

```php
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        $response->withHeader('Access-Control-Allow-Origin', '*');

        if ($request->getMethod() === 'OPTIONS') {
            $response->status(204)->send();
            return;
        }

        $next($request, $response);
    }
}
```

---

## HttpException

`Router\Exceptions\HttpException` extends `\RuntimeException` and carries an HTTP status code.
Throw it from any handler or middleware — the router will catch it and delegate to the error handler.

**Named constructors:**

```php
use Router\Exceptions\HttpException;

throw HttpException::notFound();                        // 404
throw HttpException::methodNotAllowed();                // 405
throw HttpException::unauthorized();                    // 401
throw HttpException::forbidden();                       // 403
throw HttpException::badRequest('Invalid input');       // 400
throw HttpException::unprocessableEntity('Validation failed'); // 422
throw HttpException::internalServerError('Oops');       // 500
```

**Custom status code:**

```php
throw new HttpException(429, 'Too Many Requests');
```

---

## Architecture

| Decision              | Detail                                                                      |
| --------------------- | --------------------------------------------------------------------------- |
| **No PSR-7**          | `Request`/`Response` are thin superglobal wrappers — intentionally simple.  |
| **No DI container**   | Handlers instantiated with `new $class()`. No container introduced.         |
| **No dependencies**   | Pure PHP 8.1+. Nothing in `require` beyond the PHP runtime.                 |
| **Regex routing**     | `:param` patterns compiled to named-capture regex at route registration.    |
| **Strict types**      | Every file begins with `declare(strict_types=1);`.                          |
| **Immutability**      | `Request` and `Route` are immutable; clone/wither pattern used throughout.  |
