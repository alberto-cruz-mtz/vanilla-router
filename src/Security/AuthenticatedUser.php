<?php

declare(strict_types=1);

namespace Router\Security;

/**
 * Immutable value object that represents a successfully authenticated user.
 *
 * The SecurityChain resolves this object from the incoming request and
 * attaches it to the Request so handlers can read it via $request->user().
 */
final class AuthenticatedUser
{
    /**
     * @param string   $id     Unique user identifier (DB id, sub claim, etc.)
     * @param string   $name   Display name.
     * @param string[] $roles  Role slugs, e.g. ['admin', 'editor'].
     * @param array    $extra  Any additional claims / attributes you need.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array  $roles = [],
        private readonly array  $extra = [],
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an extra claim attached during authentication.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }
}