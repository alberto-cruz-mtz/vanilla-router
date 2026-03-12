<?php

declare(strict_types=1);

namespace Router\Security\Resolvers;

use Router\Contracts\UserResolverInterface;
use Router\Request;
use Router\Security\AuthenticatedUser;

/**
 * Resolves an authenticated user from a signed JWT in the Authorization header.
 *
 * This resolver is intentionally dependency-free — it implements HS256
 * (HMAC-SHA256) verification inline so the router library has zero
 * external requirements.
 *
 * If you prefer a full JWT library (firebase/php-jwt, lcobucci/jwt, etc.)
 * you can implement UserResolverInterface yourself and delegate to it.
 *
 * Expected JWT payload:
 *
 *   {
 *     "sub":   "42",          ← mapped to AuthenticatedUser::id
 *     "name":  "Alice",       ← mapped to AuthenticatedUser::name
 *     "roles": ["admin"],     ← mapped to AuthenticatedUser::roles
 *     "exp":   1999999999     ← expiry is validated automatically
 *   }
 *
 * Usage:
 *
 *   new JwtUserResolver(secret: $_ENV['JWT_SECRET'])
 */
final class JwtUserResolver implements UserResolverInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm = 'HS256',
    ) {}

    public function resolve(Request $request): ?AuthenticatedUser
    {
        $token = $this->extractBearerToken($request);

        if ($token === null) {
            return null;
        }

        $payload = $this->decodeAndVerify($token);

        if ($payload === null) {
            return null;
        }

        return $this->buildUser($payload);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->header('authorization', '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authHeader, 7));

        return $token !== '' ? $token : null;
    }

    /**
     * Decodes and verifies a JWT string.
     * Returns the payload array on success, null on any failure.
     */
    private function decodeAndVerify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        if (!$this->verifySignature($encodedHeader, $encodedPayload, $encodedSignature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($encodedPayload);

        if ($payload === null) {
            return null;
        }

        if ($this->isExpired($payload)) {
            return null;
        }

        return $payload;
    }

    private function verifySignature(
        string $encodedHeader,
        string $encodedPayload,
        string $encodedSignature,
    ): bool {
        $signingInput   = "{$encodedHeader}.{$encodedPayload}";
        $expectedSig    = $this->sign($signingInput);
        $providedSig    = $this->base64UrlDecodeRaw($encodedSignature);

        return hash_equals($expectedSig, $providedSig);
    }

    private function sign(string $input): string
    {
        return hash_hmac('sha256', $input, $this->secret, true);
    }

    private function isExpired(array $payload): bool
    {
        if (!isset($payload['exp'])) {
            return false; // No expiry claim → treat as non-expiring.
        }

        return time() > (int) $payload['exp'];
    }

    private function buildUser(array $payload): ?AuthenticatedUser
    {
        $id   = (string) ($payload['sub']  ?? '');
        $name = (string) ($payload['name'] ?? '');

        if ($id === '' || $name === '') {
            return null;
        }

        $reserved = ['sub', 'name', 'roles', 'exp', 'iat', 'iss', 'aud'];
        $extra    = array_diff_key($payload, array_flip($reserved));

        return new AuthenticatedUser(
            id:    $id,
            name:  $name,
            roles: (array) ($payload['roles'] ?? []),
            extra: $extra,
        );
    }

    private function base64UrlDecode(string $input): ?array
    {
        $json = $this->base64UrlDecodeRaw($input);

        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecodeRaw(string $input): string
    {
        $padded = str_pad(
            strtr($input, '-_', '+/'),
            strlen($input) + (4 - strlen($input) % 4) % 4,
            '='
        );

        return base64_decode($padded, true) ?: '';
    }
}