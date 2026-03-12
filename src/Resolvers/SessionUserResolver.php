<?php

declare(strict_types=1);

namespace Router\Security\Resolvers;

use Router\Contracts\UserResolverInterface;
use Router\Request;
use Router\Security\AuthenticatedUser;

/**
 * Resolves an authenticated user from a native PHP session.
 *
 * Expected $_SESSION structure (set by your login controller):
 *
 *   $_SESSION['auth'] = [
 *       'id'    => '42',
 *       'name'  => 'Alice',
 *       'roles' => ['admin', 'editor'],
 *       // any extra fields you want available via $user->get('key')
 *   ];
 *
 * You can customise the session key via the constructor:
 *
 *   new SessionUserResolver(sessionKey: 'user')
 */
final class SessionUserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly string $sessionKey = 'auth',
    ) {}

    public function resolve(Request $request): ?AuthenticatedUser
    {
        $this->startSessionIfNeeded();

        $data = $_SESSION[$this->sessionKey] ?? null;

        if (!$this->isValidSessionData($data)) {
            return null;
        }

        return new AuthenticatedUser(
            id:    (string) $data['id'],
            name:  (string) $data['name'],
            roles: (array)  ($data['roles'] ?? []),
            extra: $this->extractExtra($data),
        );
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function startSessionIfNeeded(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    private function isValidSessionData(mixed $data): bool
    {
        return is_array($data)
            && isset($data['id'], $data['name'])
            && $data['id'] !== ''
            && $data['name'] !== '';
    }

    /**
     * Returns all fields except the reserved ones as extra attributes.
     */
    private function extractExtra(array $data): array
    {
        $reserved = ['id', 'name', 'roles'];
        return array_diff_key($data, array_flip($reserved));
    }
}