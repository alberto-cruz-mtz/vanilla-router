# AGENTS.md — vanilla-router

Coding guidelines and project reference for agentic coding tools working in this repository.

## Project Overview

`vanilla-router` is a pure PHP HTTP router library inspired by Express.js. It has zero runtime
dependencies (PHP 8.1+ only) and no frontend assets, build steps, or JavaScript of any kind.

**Source layout:**

```
src/
├── Router.php                        # Main entry point; HTTP router class
├── Route.php                         # Immutable value object for a registered route
├── Request.php                       # HTTP request wrapper (reads superglobals)
├── Response.php                      # HTTP response builder (HTML, JSON, redirect, text)
├── Contracts/
│   ├── MiddlewareInterface.php       # Interface for middleware
│   └── ErrorHandlerInterface.php     # Interface for custom error handlers
├── Exceptions/
│   └── HttpException.php             # RuntimeException subclass with HTTP status code
└── Middleware/
    └── DefaultErrorHandler.php       # Built-in error/exception handler
```

---

## Build / Dependency Commands

```bash
# Install/update the Composer autoloader (no third-party packages exist)
composer install
composer dump-autoload

# Validate composer.json
composer validate
```

There is no build step beyond generating the Composer autoloader.

---

## Lint / Static Analysis Commands

No linter or static analysis tool (PHPStan, Psalm, PHP-CS-Fixer, PHPCS) is currently configured.
If you add one, update this section with the exact command.

Suggested future setup:

```bash
# Example — add to composer.json require-dev first
vendor/bin/phpstan analyse src --level=max
vendor/bin/php-cs-fixer fix src
```

---

## Test Commands

No test suite exists yet. There is no `tests/` directory and no PHPUnit configured.

If you add tests:

```bash
# Run the full test suite
vendor/bin/phpunit

# Run a single test file
vendor/bin/phpunit tests/RouterTest.php

# Run a single test method
vendor/bin/phpunit --filter testMethodName tests/RouterTest.php

# Run with coverage (requires Xdebug or pcov)
vendor/bin/phpunit --coverage-text
```

When adding tests, place them in `tests/` and require `phpunit/phpunit` in `require-dev`.

---

## PHP Version & Strict Types

- Minimum PHP version: **8.1**
- **Every source file must begin with `declare(strict_types=1);`**
- Freely use PHP 8.1+ features: `readonly` properties, named arguments, union types,
  `match` expressions, `str_contains()`, `str_starts_with()`, fibers if needed.

---

## Code Style Guidelines

### General Formatting

- **Indentation**: 4 spaces (no tabs).
- **Braces**: Opening brace on the same line for single-line signatures; on its own line
  after the closing `)` of a multi-line signature.
- **Trailing commas**: Always include trailing commas in multi-line argument/parameter lists.
- **Column alignment**: Align property declarations and parameter types in columns when it
  improves readability (use spaces, not tabs).

```php
// Multi-line signature — closing paren + return type on a dedicated line, brace below
private function addRoute(
    string         $method,
    string         $path,
    callable|array $handler,
    array          $middlewares,
): self
{
    // ...
}

// Aligned property declarations
private int    $statusCode = 200;
private array  $headers    = [];
private string $body       = '';
private bool   $sent       = false;
```

### Namespace & File Organization

- Root namespace: `Router\` (PSR-4, mapped to `src/`).
- Sub-namespaces: `Router\Contracts`, `Router\Exceptions`, `Router\Middleware`.
- **One class or interface per file**; filename must match class/interface name exactly.
- `use` imports come after `declare(strict_types=1);` and the `namespace` declaration,
  grouped (standard library, then project-local), with a blank line between groups.

### Class Design

- Mark all concrete classes `final` unless extension is explicitly intended.
- Use `interface` for contracts (`*Interface` suffix).
- Exception subclasses need not be `final` to allow further specialization.
- Prefer **immutable value objects** using `private readonly` constructor promotion.
- Provide **named constructors** (static factory methods) instead of complex constructors:
  `HttpException::notFound()`, `HttpException::badRequest()`, etc.
- Use the **clone pattern** for "wither" methods that return a modified copy:

```php
public function withRouteParams(array $params): self
{
    $clone = clone $this;
    $clone->routeParams = $params;
    return $clone;
}
```

- **Fluent/builder interfaces**: methods that configure state should return `self` to allow
  chaining (`$router->get(...)->post(...)->use(...)`).
- Use `static function` for closures that do not capture `$this`.

### Naming Conventions

| Construct              | Convention       | Example                                            |
| ---------------------- | ---------------- | -------------------------------------------------- |
| Classes / Interfaces   | `PascalCase`     | `Router`, `HttpException`                          |
| Methods                | `camelCase`      | `getMethod()`, `resolveHandler()`                  |
| Properties / Variables | `camelCase`      | `$statusCode`, `$paramKeys`                        |
| HTTP verb strings      | `UPPERCASE`      | `'GET'`, `'POST'`                                  |
| Private helpers        | descriptive verb | `assert*`, `resolve*`, `parse*`, `build*`, `send*` |

### Type System

- **All parameters, properties, and return types must be explicitly declared.**
- Use `mixed` only for genuinely flexible types (e.g., handler callables, parsed body values).
- Use `?Type` for truly optional nullable values; prefer non-nullable when possible.
- Use union types directly: `callable|array`, `string|int`.
- Annotate generic shapes with docblock generics:
  - `@return array<string, string>`
  - `@param callable|array{class-string, string} $handler`
  - `@return array{string, array<string>}`
- `@var Route[] $routes` for typed array properties.

### DocBlocks & Comments

- Add a class-level docblock with a brief description and usage example for public-facing classes.
- Add method docblocks (`@param`, `@return`, `@throws`) only when the signature alone is not
  self-explanatory.
- Use visual section dividers for logical groupings within large classes:
  ```php
  // ─── Route registration ───────────────────────────────────────────────────
  ```

### Error Handling

- Throw `HttpException` (or a named-constructor variant) for all HTTP-level errors.
- `Router::run()` must wrap the entire dispatch cycle in `try/catch (\Throwable)` and delegate
  to `ErrorHandlerInterface`; include a second safety-net catch for errors thrown inside the
  error handler itself.
- Use `JSON_THROW_ON_ERROR` with `json_encode`/`json_decode`; catch `\JsonException` explicitly
  rather than checking `json_last_error()`.
- Use anonymous `catch (\Throwable)` (no variable) when the exception object is not used.

```php
try {
    // dispatch
} catch (HttpException $e) {
    $this->errorHandler->handle($e, $request, $response);
} catch (\Throwable $e) {
    $this->errorHandler->handle(
        HttpException::internalServerError($e->getMessage()),
        $request,
        $response,
    );
}
```

### Middleware Pattern

Middleware must implement `MiddlewareInterface`:

```php
public function handle(Request $request, Response $response, callable $next): void;
```

- Call `$next($request, $response)` to pass control to the next layer.
- Middleware may short-circuit by not calling `$next`.

---

## Key Architectural Decisions

- **No PSR-7**: `Request`/`Response` are intentionally thin wrappers over PHP superglobals,
  not PSR-7 compliant. Keep them simple.
- **No DI container**: Handlers are instantiated with `new $class()`. Do not introduce a
  container unless there is a strong explicit reason.
- **No framework dependency**: This library is the framework. Do not pull in Symfony, Laravel,
  or similar packages.
- **Regex-compiled routing**: Path patterns use `:param` syntax compiled to named-capture
  regex. Extend `Route::compile()` for new pattern features.
