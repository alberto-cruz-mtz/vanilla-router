<?php

declare(strict_types=1);

namespace Router;

/**
 * Encapsulates the incoming HTTP request data.
 * Provides safe access to query params, body, headers, and route params.
 */
final class Request
{
    private array $routeParams = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array  $queryParams,
        private readonly array  $bodyParams,
        private readonly array  $headers,
        private readonly array  $files,
        private readonly string $rawBody,
    ) {}

    public static function fromGlobals(): self
    {
        return new self(
            method:      strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path:        self::parsePath(),
            queryParams: $_GET,
            bodyParams:  self::parseBodyParams(),
            headers:     self::parseHeaders(),
            files:       $_FILES,
            rawBody:     file_get_contents('php://input') ?: '',
        );
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

    /**
     * Returns a sanitized query parameter or a default value.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->queryParams[$key] ?? $default);
    }

    /**
     * Returns all query parameters sanitized.
     */
    public function allQuery(): array
    {
        return array_map([$this, 'sanitize'], $this->queryParams);
    }

    /**
     * Returns a sanitized body parameter or a default value.
     */
    public function body(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->bodyParams[$key] ?? $default);
    }

    /**
     * Returns all body parameters sanitized.
     */
    public function allBody(): array
    {
        return array_map([$this, 'sanitize'], $this->bodyParams);
    }

    /**
     * Returns a route parameter (e.g. :id) or a default value.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->routeParams[$key] ?? $default);
    }

    /**
     * Returns all route parameters.
     */
    public function allParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Returns a header value or a default.
     */
    public function header(string $key, ?string $default = null): ?string
    {
        $normalized = strtolower($key);
        return $this->headers[$normalized] ?? $default;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Returns the decoded JSON body as an associative array.
     *
     * @throws \JsonException
     */
    public function json(): array
    {
        if (empty($this->rawBody)) {
            return [];
        }

        return json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');
        return str_contains($contentType, 'application/json');
    }

    public function isXhr(): bool
    {
        return $this->header('x-requested-with') === 'XMLHttpRequest';
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Called by the Router after matching to inject named route params.
     */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitize'], $value);
        }

        return $value;
    }

    private static function parsePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . trim($path, '/');
    }

    private static function parseBodyParams(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if (empty($raw)) {
                return [];
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                return is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                return [];
            }
        }

        return $_POST;
    }

    private static function parseHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $raw = getallheaders();
            return array_change_key_case($raw, CASE_LOWER);
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }

        return $headers;
    }
}
