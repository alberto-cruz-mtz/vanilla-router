<?php

namespace Router\Contracts;

use Router\Request;
use Router\Security\AuthenticatedUser;

/**
 * Resolves the authenticated user from an incoming HTTP request.
 *
 * Implement this interface to plug any authentication backend
 * (JWT, session, API key, OAuth, etc.) into the SecurityChain.
 * The SecurityChain calls `resolve()` on every request and, when a
 * non-null value is returned, attaches the user to the Request via
 * `$request->withUser()`, making it available to handlers through
 * `$request->user()`.
 *
 * Example implementation (Bearer JWT):
 *
 * ```php
 * final class JwtUserResolver implements UserResolverInterface
 * {
 *     public function resolve(Request $request): ?AuthenticatedUser
 *     {
 *         $token = $request->header('authorization');
 *         if ($token === null || !str_starts_with($token, 'Bearer ')) {
 *             return null;
 *         }
 *
 *         $payload = $this->jwtService->verify(substr($token, 7));
 *         if ($payload === null) {
 *             return null;
 *         }
 *
 *         return new AuthenticatedUser(
 *             id:    $payload['sub'],
 *             name:  $payload['name'],
 *             roles: $payload['roles'] ?? [],
 *         );
 *     }
 * }
 * ```
 */
interface UserResolverInterface
{
    /**
     * Attempts to resolve an authenticated user from the request.
     *
     * Return a populated `AuthenticatedUser` when credentials are valid,
     * or `null` when the request is anonymous / credentials are absent or invalid.
     * Throw an `HttpException` (e.g. `HttpException::unauthorized()`) if you want
     * the SecurityChain to immediately abort with an HTTP error response.
     *
     * @param  Request                $request The current HTTP request.
     * @return AuthenticatedUser|null          Resolved user, or null if unauthenticated.
     */
    public function resolve(Request $request): ?AuthenticatedUser;
}