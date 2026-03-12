<?php

declare(strict_types=1);

namespace Router\Security;

/**
 * Immutable value object that pairs a path pattern with an access policy.
 *
 * Rules are evaluated in registration order — the first match wins,
 * exactly like Spring Security's HttpSecurity rule chain.
 *
 * Do NOT instantiate this class directly. Use SecurityChain's fluent API:
 *
 *   $chain->path('/admin/*')->hasRole('admin')
 *   $chain->path('/api/*')->authenticated()
 *   $chain->path('/login')->permitAll()
 */
final class SecurityRule
{
    public const POLICY_PERMIT_ALL    = 'permit_all';
    public const POLICY_AUTHENTICATED = 'authenticated';
    public const POLICY_HAS_ROLE      = 'has_role';
    public const POLICY_HAS_ANY_ROLE  = 'has_any_role';

    /**
     * @param string   $pattern  Glob-style path, e.g. /admin/* or /users/:id.
     * @param string   $policy   One of the POLICY_* constants.
     * @param string[] $roles    Required roles (only for HAS_ROLE / HAS_ANY_ROLE).
     */
    public function __construct(
        private readonly string $pattern,
        private readonly string $policy,
        private readonly array  $roles = [],
    ) {}

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getPolicy(): string
    {
        return $this->policy;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Returns true when the given request path matches this rule's pattern.
     *
     * Supports:
     *   - Exact match:      /login
     *   - Wildcard suffix:  /admin/*  (matches /admin/users, /admin/settings, …)
     *   - Named params:     /users/:id
     */
    public function matchesPath(string $requestPath): bool
    {
        $normalized = '/' . trim($requestPath, '/');

        // Wildcard: /admin/* matches anything under /admin/
        if (str_ends_with($this->pattern, '/*')) {
            $prefix = rtrim($this->pattern, '/*');
            return str_starts_with($normalized, $prefix . '/') || $normalized === $prefix;
        }

        // Named params: /users/:id  → convert to regex like Route::compile does
        $regex = $this->compileToRegex($this->pattern);
        return (bool) preg_match($regex, $normalized);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function compileToRegex(string $pattern): string
    {
        $normalized = '/' . trim($pattern, '/');
        $escaped    = preg_quote($normalized, '#');

        // Replace escaped :param placeholders with a capture group.
        $regex = preg_replace('#\\\:([a-zA-Z_][a-zA-Z0-9_]*)#', '[^/]+', $escaped);

        return '#^' . $regex . '$#';
    }
}