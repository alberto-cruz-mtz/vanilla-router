<?php

declare(strict_types=1);

namespace Router;

/**
 * Immutable value object that represents a registered route.
 */
final class Route
{
    /** Regex pattern compiled from the route definition. */
    private readonly string $pattern;

    /** Named parameter keys extracted from the route definition (e.g. :id → 'id'). */
    private readonly array $paramKeys;

    /**
     * @param string $method HTTP verb in uppercase (GET, POST, …).
     * @param string $path Route definition, e.g. /users/:id.
     * @param callable $handler The handler invoked on match.
     * @param array $middlewares Per-route middlewares (executed before the handler).
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed  $handler,
        private readonly array  $middlewares = [],
    )
    {
        [$this->pattern, $this->paramKeys] = self::compile($path);
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHandler(): mixed
    {
        return $this->handler;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    // ─── Matching ─────────────────────────────────────────────────────────────

    /**
     * Returns true when this route matches the given HTTP method and URL path.
     */
    public function matches(string $method, string $path): bool
    {
        if ($this->method !== strtoupper($method)) {
            return false;
        }

        return (bool)preg_match($this->pattern, $path);
    }

    /**
     * Extracts named route parameters from the given path.
     *
     * @return array<string, string>
     */
    public function extractParams(string $path): array
    {
        if (empty($this->paramKeys)) {
            return [];
        }

        preg_match($this->pattern, $path, $matches);

        $values = array_slice($matches, 1);
        return array_combine($this->paramKeys, $values);
    }

    // ─── Compilation ─────────────────────────────────────────────────────────

    /**
     * Converts an Express-style route definition to a regex pattern.
     *
     * Examples:
     *   /users/:id        → /^\/users\/([^\/]+)$/
     *   /posts/:slug/edit → /^\/posts\/([^\/]+)\/edit$/
     *
     * @return array{string, array<string>}  [pattern, paramKeys]
     */
    private static function compile(string $path): array
    {
        $paramKeys = [];

        $normalized = '/' . trim($path, '/');

        $escaped = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $matches) use (&$paramKeys): string {
                $paramKeys[] = $matches[1];
                return '([^\/]+)';
            },
            preg_quote($normalized, '#'),
        );

        // Undo the escaping applied to our own capture groups.
        $pattern = '#^' . str_replace('\(\[\^\\\/\]\+\)', '([^\/]+)', $escaped) . '$#';

        return [$pattern, $paramKeys];
    }
}