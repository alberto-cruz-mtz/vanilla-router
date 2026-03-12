<?php

declare(strict_types=1);

namespace Router\Exceptions;

/**
 * Represents an HTTP error that should be forwarded to the error handler
 * with the associated HTTP status code.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string               $message = '',
        ?\Throwable          $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    // ─── Named constructors ───────────────────────────────────────────────────

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self(404, $message);
    }

    public static function methodNotAllowed(string $message = 'Method Not Allowed'): self
    {
        return new self(405, $message);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = 'Bad Request'): self
    {
        return new self(400, $message);
    }

    public static function unprocessableEntity(string $message = 'Unprocessable Entity'): self
    {
        return new self(422, $message);
    }

    public static function internalServerError(string $message = 'Internal Server Error'): self
    {
        return new self(500, $message);
    }
}