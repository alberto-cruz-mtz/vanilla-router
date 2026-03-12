<?php

declare(strict_types=1);

namespace Router;

/**
 * Builds and sends the HTTP response.
 * Supports HTML views, JSON payloads, redirects, and custom headers.
 */
final class Response
{
    private int    $statusCode = 200;
    private array  $headers    = [];
    private string $body       = '';
    private bool   $sent       = false;

    // ─── Status setters ───────────────────────────────────────────────────────

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    // ─── Header helpers ───────────────────────────────────────────────────────

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    // ─── Body builders ────────────────────────────────────────────────────────

    /**
     * Sends an HTML response.
     */
    public function html(string $content, int $statusCode = 200): void
    {
        $this->status($statusCode)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->body = $content;
        $this->send();
    }

    /**
     * Renders a PHP view file and sends it as HTML.
     *
     * @param string $viewPath Absolute path to the .php view file.
     * @param array  $data     Variables extracted into the view scope.
     * @throws \RuntimeException When the view file is not found.
     */
    public function view(string $viewPath, array $data = [], int $statusCode = 200): void
    {
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View file not found: {$viewPath}");
        }

        $this->status($statusCode)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');

        $this->body = $this->renderView($viewPath, $data);
        $this->send();
    }

    /**
     * Sends a JSON response.
     *
     * @throws \JsonException On encoding failure.
     */
    public function json(mixed $data, int $statusCode = 200): void
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->status($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');

        $this->body = $encoded;
        $this->send();
    }

    /**
     * Sends a JSON error envelope.
     */
    public function jsonError(string $message, int $statusCode = 400, mixed $details = null): void
    {
        $payload = ['error' => true, 'message' => $message];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        $this->json($payload, $statusCode);
    }

    /**
     * Sends a plain text response.
     */
    public function text(string $content, int $statusCode = 200): void
    {
        $this->status($statusCode)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->body = $content;
        $this->send();
    }

    /**
     * Redirects the client to a given URL.
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        $this->status($statusCode)
            ->withHeader('Location', $url);

        $this->body = '';
        $this->send();
    }

    // ─── Send ─────────────────────────────────────────────────────────────────

    /**
     * Emits status line, headers, and body to the client.
     */
    public function send(): void
    {
        if ($this->sent) {
            return;
        }

        $this->sent = true;

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    private function renderView(string $viewPath, array $data): string
    {
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $viewPath;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean() ?: '';
    }
}