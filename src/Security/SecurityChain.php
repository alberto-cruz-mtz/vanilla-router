<?php
declare(strict_types=1);

namespace Router\Security;

use Router\Contracts\MiddlewareInterface;
use Router\Contracts\UserResolverInterface;
use Router\Exceptions\HttpException;
use Router\Request;
use Router\Response;

/**
 * Spring Security-inspired filter chain for the PHP Router.
 *
 * Registers as a single global middleware via $router->use($chain).
 * Evaluates rules in order — first match wins.
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  $chain = SecurityChain::configure($resolver)               │
 * │      ->path('/login')->permitAll()                          │
 * │      ->path('/register')->permitAll()                       │
 * │      ->path('/admin/*')->hasRole('admin')                   │
 * │      ->path('/api/admin/*')->hasAnyRole('admin', 'support') │
 * │      ->path('/api/*')->authenticated()                      │
 * │      ->path('/*')->permitAll()     ← open catch-all         │
 * │      ->onUnauthorized(...)         ← optional override      │
 * │      ->onForbidden(...);           ← optional override      │
 * │                                                             │
 * │  $router->use($chain);                                      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * If no rule matches, access is DENIED by default (fail-secure).
 */
final class SecurityChain implements MiddlewareInterface
{
    /** @var SecurityRule[] Ordered list of access rules. */
    private array $rules = [];

    /**
     * Called when the user is not authenticated (401).
     * Default: redirect to /login for HTML requests, JSON 401 for API requests.
     */
    private ?callable $unauthorizedHandler = null;

    /**
     * Called when the user is authenticated but lacks the required role (403).
     * Default: JSON 403 for API requests, redirect to / for HTML.
     */
    private ?callable $forbiddenHandler = null;

    // ─── Constructor ─────────────────────────────────────────────────────────

    private function __construct(
        private readonly UserResolverInterface $userResolver,
    )
    {
    }

    public static function configure(UserResolverInterface $userResolver): self
    {
        return new self($userResolver);
    }

    // ─── Fluent rule API ─────────────────────────────────────────────────────

    /**
     * Starts a rule definition for the given path pattern.
     * Returns a RuleBuilder so you can chain the policy method.
     *
     * @example
     *   ->path('/admin/*')->hasRole('admin')
     *   ->path('/api/*')->authenticated()
     *   ->path('/login')->permitAll()
     */
    public function path(string $pattern): RuleBuilder
    {
        return new RuleBuilder($pattern, $this);
    }

    /**
     * Called internally by RuleBuilder to register the completed rule.
     * Not intended for direct use.
     */
    public function addRule(SecurityRule $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    // ─── Custom response handlers ─────────────────────────────────────────────

    /**
     * Overrides the default 401 handler.
     *
     * The callable receives (Request $request, Response $response).
     *
     * @example — redirect to login page
     *   ->onUnauthorized(function (Request $req, Response $res): void {
     *       $res->redirect('/login');
     *   })
     *
     * @example — always return JSON 401
     *   ->onUnauthorized(function (Request $req, Response $res): void {
     *       $res->json(['error' => 'Unauthenticated'], 401);
     *   })
     */
    public function onUnauthorized(callable $handler): self
    {
        $this->unauthorizedHandler = $handler;
        return $this;
    }

    /**
     * Overrides the default 403 handler.
     *
     * The callable receives (Request $request, Response $response).
     */
    public function onForbidden(callable $handler): self
    {
        $this->forbiddenHandler = $handler;
        return $this;
    }

    // ─── MiddlewareInterface ──────────────────────────────────────────────────

    public function handle(Request $request, Response $response, callable $next): void
    {
        $rule = $this->findMatchingRule($request->getPath());

        // No rule matched → fail-secure: deny access.
        if ($rule === null) {
            $this->denyUnauthorized($request, $response);
            return;
        }

        // permitAll — skip any auth check.
        if ($rule->getPolicy() === SecurityRule::POLICY_PERMIT_ALL) {
            $next($request);
            return;
        }

        // All other policies require a resolved user.
        $user = $this->userResolver->resolve($request);

        if ($user === null) {
            $this->denyUnauthorized($request, $response);
            return;
        }

        // Attach the user to the request so handlers can access it.
        $authenticatedRequest = $request->withUser($user);

        if (!$this->userSatisfiesPolicy($user, $rule)) {
            $this->denyForbidden($authenticatedRequest, $response);
            return;
        }

        $next($authenticatedRequest);
    }

    // ─── Private — rule evaluation ────────────────────────────────────────────

    private function findMatchingRule(string $path): ?SecurityRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matchesPath($path)) {
                return $rule;
            }
        }

        return null;
    }

    private function userSatisfiesPolicy(AuthenticatedUser $user, SecurityRule $rule): bool
    {
        return match ($rule->getPolicy()) {
            SecurityRule::POLICY_AUTHENTICATED => true, // user exists → OK
            SecurityRule::POLICY_HAS_ROLE => $this->userHasAllRoles($user, $rule->getRoles()),
            SecurityRule::POLICY_HAS_ANY_ROLE => $user->hasAnyRole(...$rule->getRoles()),
            default => false,
        };
    }

    private function userHasAllRoles(AuthenticatedUser $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if (!$user->hasRole($role)) {
                return false;
            }
        }

        return true;
    }

    // ─── Private — deny responses ─────────────────────────────────────────────

    private function denyUnauthorized(Request $request, Response $response): void
    {
        if ($this->unauthorizedHandler !== null) {
            ($this->unauthorizedHandler)($request, $response);
            return;
        }

        $this->defaultUnauthorizedResponse($request, $response);
    }

    private function denyForbidden(Request $request, Response $response): void
    {
        if ($this->forbiddenHandler !== null) {
            ($this->forbiddenHandler)($request, $response);
            return;
        }

        $this->defaultForbiddenResponse($request, $response);
    }

    /**
     * Default 401: JSON for API/XHR requests, redirect to /login for browsers.
     */
    private function defaultUnauthorizedResponse(Request $request, Response $response): void
    {
        if ($this->isApiRequest($request)) {
            $response->json(['error' => true, 'message' => 'Unauthenticated.'], 401);
            return;
        }

        $response->redirect('/login');
    }

    /**
     * Default 403: JSON for API/XHR requests, redirect to / for browsers.
     */
    private function defaultForbiddenResponse(Request $request, Response $response): void
    {
        if ($this->isApiRequest($request)) {
            $response->json(['error' => true, 'message' => 'Forbidden. Insufficient permissions.'], 403);
            return;
        }

        $response->redirect('/');
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->isJson() || $request->isXhr();
    }
}