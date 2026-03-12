<?php

declare(strict_types=1);

namespace Router\Security;

/**
 * Fluent builder returned by SecurityChain::path().
 *
 * Lets you write:
 *
 *   $chain->path('/admin/*')->hasRole('admin')
 *   $chain->path('/api/*')->authenticated()
 *   $chain->path('/login')->permitAll()
 */
final class RuleBuilder
{
    public function __construct(
        private readonly string        $pattern,
        private readonly SecurityChain $chain,
    ) {}

    /**
     * Anyone may access these paths — no authentication required.
     */
    public function permitAll(): SecurityChain
    {
        return $this->chain->addRule(
            new SecurityRule($this->pattern, SecurityRule::POLICY_PERMIT_ALL)
        );
    }

    /**
     * The request must carry a valid authenticated user (any role).
     */
    public function authenticated(): SecurityChain
    {
        return $this->chain->addRule(
            new SecurityRule($this->pattern, SecurityRule::POLICY_AUTHENTICATED)
        );
    }

    /**
     * The authenticated user must have ALL the specified roles.
     */
    public function hasRole(string ...$roles): SecurityChain
    {
        return $this->chain->addRule(
            new SecurityRule($this->pattern, SecurityRule::POLICY_HAS_ROLE, $roles)
        );
    }

    /**
     * The authenticated user must have AT LEAST ONE of the specified roles.
     */
    public function hasAnyRole(string ...$roles): SecurityChain
    {
        return $this->chain->addRule(
            new SecurityRule($this->pattern, SecurityRule::POLICY_HAS_ANY_ROLE, $roles)
        );
    }
}