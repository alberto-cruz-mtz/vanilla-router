<?php

declare(strict_types=1);

namespace Router\Security\Resolvers;

use Router\Contracts\UserResolverInterface;
use Router\Request;
use Router\Security\AuthenticatedUser;

/**
 * Composite resolver that tries a list of resolvers in order,
 * returning the first non-null result.
 *
 * Use this when you want to support both sessions AND JWT in the same app:
 *
 *   $resolver = new ChainUserResolver(
 *       new SessionUserResolver(),
 *       new JwtUserResolver(secret: $_ENV['JWT_SECRET']),
 *   );
 */
final class ChainUserResolver implements UserResolverInterface
{
    /** @var UserResolverInterface[] */
    private array $resolvers;

    public function __construct(UserResolverInterface ...$resolvers)
    {
        $this->resolvers = $resolvers;
    }

    public function resolve(Request $request): ?AuthenticatedUser
    {
        foreach ($this->resolvers as $resolver) {
            $user = $resolver->resolve($request);

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}