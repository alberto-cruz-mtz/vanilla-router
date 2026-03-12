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
- [Security](#security)
  - [Overview](#overview)
  - [SecurityChain](#securitychain)
  - [Defining Rules](#defining-rules)
  - [Path Pattern Matching](#path-pattern-matching)
  - [Access Policies](#access-policies)
  - [Custom Unauthorized and Forbidden Responses](#custom-unauthorized-and-forbidden-responses)
  - [UserResolverInterface](#userresolverinterface)
  - [Built-in Resolvers](#built-in-resolvers)
    - [JwtUserResolver](#jwtuserresolver)
    - [SessionUserResolver](#sessionuserresolver)
    - [ChainUserResolver](#chainuserresolver)
  - [AuthenticatedUser](#authenticateduser)
  - [Accessing the User in Handlers](#accessing-the-user-in-handlers)
  - [Full Example](#full-example)
  - [Execution Flow](#execution-flow)
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

## Security

### Overview

`vanilla-router` includes a declarative security layer inspired by **Spring Security's
`SecurityFilterChain`**. It lets you define per-path access policies in a single fluent chain
that plugs into the router as standard global middleware — no changes to your route definitions
are ever required.

Key properties:

- **Fail-secure by default** — if no rule matches the incoming path, the request is denied (401).
- **First-match-wins** — rules are evaluated in registration order; the first matching rule
  decides the outcome.
- **Zero coupling** — `SecurityChain` implements `MiddlewareInterface` and is registered with
  `$router->use()` like any other middleware.
- **Immutable request propagation** — the resolved user is attached to the request via the
  clone/wither pattern (`withUser()`), so the original request object is never mutated.
- **No external dependencies** — JWT verification is performed inline using only PHP's built-in
  `hash_hmac` and `hash_equals`.

---

### SecurityChain

`Router\Security\SecurityChain` is the core class. Create it with the static factory and pass
a `UserResolverInterface` implementation:

```php
use Router\Security\SecurityChain;
use Router\Resolvers\JwtUserResolver;

$security = SecurityChain::configure(
    new JwtUserResolver(secret: $_ENV['JWT_SECRET'])
);

$router->use($security);
```

All rule registration methods return the `SecurityChain` instance, allowing fluent chaining.

---

### Defining Rules

Use `SecurityChain::path(string $pattern)` to start a rule. It returns a `RuleBuilder` whose
terminal methods register the rule and return the chain:

```php
$security = SecurityChain::configure($userResolver)
    ->path('/login')->permitAll()
    ->path('/register')->permitAll()
    ->path('/admin/*')->hasRole('admin')
    ->path('/api/*')->authenticated()
    ->path('/*')->authenticated();
```

> Rules are evaluated **top to bottom**. Place more-specific patterns before wildcards.

---

### Path Pattern Matching

Three matching modes are supported:

| Mode            | Example            | Matches                                      |
| --------------- | ------------------ | -------------------------------------------- |
| Exact           | `/login`           | Only `/login`                                |
| Wildcard suffix | `/admin/*`         | `/admin/`, `/admin/users`, `/admin/x/y/z`    |
| Named parameter | `/users/:id`       | `/users/42`, `/users/abc`                    |

---

### Access Policies

| Builder method              | Policy                                                             |
| --------------------------- | ------------------------------------------------------------------ |
| `->permitAll()`             | No authentication check; request passes through immediately.       |
| `->authenticated()`         | Request must carry a valid authenticated user (any role).          |
| `->hasRole('a', 'b')`       | User must have **all** listed roles (AND logic).                   |
| `->hasAnyRole('a', 'b')`    | User must have **at least one** of the listed roles (OR logic).    |

```php
SecurityChain::configure($resolver)
    // Public paths
    ->path('/login')->permitAll()
    ->path('/api/v1/token')->permitAll()

    // Admin area — must have the 'admin' role
    ->path('/admin/*')->hasRole('admin')

    // Support portal — 'admin' OR 'support'
    ->path('/api/v1/tickets/*')->hasAnyRole('admin', 'support')

    // Everything else — any authenticated user
    ->path('/*')->authenticated();
```

---

### Custom Unauthorized and Forbidden Responses

By default the chain responds based on whether the request appears to be an API/XHR call or a
regular browser request:

| Condition          | API / XHR                                          | Browser          |
| ------------------ | -------------------------------------------------- | ---------------- |
| Not authenticated  | `{"error":true,"message":"Unauthenticated."} 401`  | Redirect `/login` |
| Insufficient role  | `{"error":true,"message":"Forbidden..."} 403`      | Redirect `/`     |

Override either behaviour with `onUnauthorized()` and `onForbidden()`:

```php
use Router\Request;
use Router\Response;

$security = SecurityChain::configure($resolver)
    ->path('/*')->authenticated()
    ->onUnauthorized(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Please log in.'], 401);
            return;
        }
        $res->redirect('/login');
    })
    ->onForbidden(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Access denied.'], 403);
            return;
        }
        $res->redirect('/403');
    });
```

---

### UserResolverInterface

`Router\Contracts\UserResolverInterface` is the single integration point between the security
system and your authentication backend:

```php
interface UserResolverInterface
{
    public function resolve(Request $request): ?AuthenticatedUser;
}
```

Return an `AuthenticatedUser` when credentials are valid, `null` when the request is anonymous
or credentials are absent/invalid, or throw an `HttpException` to abort immediately with an
HTTP error response.

**Custom resolver example (API key):**

```php
final class ApiKeyResolver implements UserResolverInterface
{
    public function resolve(Request $request): ?AuthenticatedUser
    {
        $key = $request->header('x-api-key');

        if ($key === null) {
            return null;
        }

        $record = $this->db->findByApiKey($key);

        if ($record === null) {
            return null;
        }

        return new AuthenticatedUser(
            id:    (string) $record['id'],
            name:  $record['name'],
            roles: $record['roles'],
        );
    }
}
```

---

### Built-in Resolvers

#### JwtUserResolver

`Router\Resolvers\JwtUserResolver` verifies **HS256 JWT Bearer tokens** with no external
library. It reads `Authorization: Bearer <token>`, verifies the HMAC-SHA256 signature, checks
the `exp` claim, and maps standard claims to `AuthenticatedUser`:

| JWT claim | Maps to                      |
| --------- | ---------------------------- |
| `sub`     | `AuthenticatedUser::$id`     |
| `name`    | `AuthenticatedUser::$name`   |
| `roles`   | `AuthenticatedUser::$roles`  |
| others    | `AuthenticatedUser::$extra`  |

```php
use Router\Resolvers\JwtUserResolver;

$resolver = new JwtUserResolver(
    secret:    $_ENV['JWT_SECRET'],
    algorithm: 'HS256',           // default — currently only HS256 is supported
);
```

Returns `null` on any failure: missing header, malformed token, invalid signature, or expired
token.

---

#### SessionUserResolver

`Router\Resolvers\SessionUserResolver` reads authentication data from `$_SESSION`. It starts
the session automatically if it has not been started yet.

Expected session structure:

```php
$_SESSION['auth'] = [
    'id'    => '42',
    'name'  => 'Alice',
    'roles' => ['editor'],
    // any additional fields are passed to AuthenticatedUser::$extra
];
```

```php
use Router\Resolvers\SessionUserResolver;

// Default session key is 'auth'
$resolver = new SessionUserResolver(sessionKey: 'auth');
```

Returns `null` if the session key is absent or `id`/`name` are missing.

---

#### ChainUserResolver

`Router\Resolvers\ChainUserResolver` is a **composite resolver** that tries each delegate in
order and returns the first non-null result. Use it to support multiple authentication
mechanisms simultaneously (e.g. session for web pages and JWT for API endpoints):

```php
use Router\Resolvers\ChainUserResolver;
use Router\Resolvers\SessionUserResolver;
use Router\Resolvers\JwtUserResolver;

$resolver = new ChainUserResolver(
    new SessionUserResolver(sessionKey: 'auth'),
    new JwtUserResolver(secret: $_ENV['JWT_SECRET']),
);
```

---

### AuthenticatedUser

`Router\Security\AuthenticatedUser` is an immutable value object that represents a
successfully authenticated user.

```php
$user->getId();                         // string — unique identifier
$user->getName();                       // string — display name
$user->getRoles();                      // string[] — role slugs
$user->hasRole('admin');                // bool — exact role check
$user->hasAnyRole('admin', 'editor');   // bool — OR check
$user->get('department', 'unknown');    // mixed — extra claim with optional default
```

---

### Accessing the User in Handlers

When a request reaches a protected handler the user is guaranteed to be non-null:

```php
$router->get('/dashboard', static function (Request $req, Response $res): void {
    $user = $req->user(); // AuthenticatedUser — always set inside a protected route
    $res->html('<h1>Welcome, ' . $user->getName() . '</h1>');
});

$router->get('/profile', static function (Request $req, Response $res): void {
    $user = $req->user();
    $res->json([
        'id'    => $user->getId(),
        'name'  => $user->getName(),
        'roles' => $user->getRoles(),
    ]);
});
```

You can also check authentication status on routes not covered by a security rule:

```php
if ($req->isAuthenticated()) {
    // $req->user() is non-null
}
```

---

### Full Example

```php
<?php

require 'vendor/autoload.php';

use Router\Router;
use Router\Request;
use Router\Response;
use Router\Security\SecurityChain;
use Router\Resolvers\ChainUserResolver;
use Router\Resolvers\SessionUserResolver;
use Router\Resolvers\JwtUserResolver;

// 1. Build a resolver: try session first, then JWT Bearer token
$resolver = new ChainUserResolver(
    new SessionUserResolver(sessionKey: 'auth'),
    new JwtUserResolver(secret: $_ENV['JWT_SECRET'] ?? 'change-me'),
);

// 2. Declare the security chain — rules are first-match-wins, top to bottom
$security = SecurityChain::configure($resolver)
    ->path('/login')->permitAll()
    ->path('/register')->permitAll()
    ->path('/api/v1/token')->permitAll()
    ->path('/admin/*')->hasRole('admin')
    ->path('/api/v1/tickets/*')->hasAnyRole('admin', 'support')
    ->path('/api/*')->authenticated()
    ->path('/*')->authenticated()
    ->onUnauthorized(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Unauthenticated.'], 401);
            return;
        }
        $res->redirect('/login');
    })
    ->onForbidden(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Forbidden.'], 403);
            return;
        }
        $res->redirect('/');
    });

// 3. Register with the router — one line secures the entire application
$router = new Router();
$router->use($security);

// 4. Define routes — no auth logic needed inside handlers
$router->get('/login', static function (Request $req, Response $res): void {
    $res->html('<form method="post" action="/login">...</form>');
});

$router->get('/admin/dashboard', static function (Request $req, Response $res): void {
    $user = $req->user(); // guaranteed non-null — SecurityChain enforced hasRole('admin')
    $res->html('<h1>Admin Dashboard — ' . $user->getName() . '</h1>');
});

$router->get('/api/v1/me', static function (Request $req, Response $res): void {
    $user = $req->user();
    $res->json([
        'id'    => $user->getId(),
        'name'  => $user->getName(),
        'roles' => $user->getRoles(),
    ]);
});

$router->dispatch();
```

---

### Execution Flow

```
$router->use($securityChain)
         │
         ▼
SecurityChain::handle(Request, Response, $next)
         │
         ├─ 1. Find the first SecurityRule whose pattern matches the request path
         │        │
         │        └─ No match → deny 401 (fail-secure)
         │
         ├─ 2. Rule policy == PERMIT_ALL?
         │        └─ Yes → call $next($request) immediately, skip all auth checks
         │
         ├─ 3. Call UserResolverInterface::resolve($request)
         │        │
         │        └─ Returns null → deny 401 (unauthenticated)
         │
         ├─ 4. Attach user: $request = $request->withUser($user)  [immutable clone]
         │
         ├─ 5. Does the user satisfy the rule's policy?
         │        │  AUTHENTICATED  → always yes
         │        │  HAS_ROLE       → user must have ALL listed roles
         │        │  HAS_ANY_ROLE   → user must have AT LEAST ONE listed role
         │        │
         │        └─ No → deny 403 (forbidden)
         │
         └─ 6. Call $next($authenticatedRequest)
                  └─ Route handler executes; $req->user() returns AuthenticatedUser
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
