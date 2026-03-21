<?php

declare(strict_types=1);

namespace Router\Tests;

use PHPUnit\Framework\TestCase;
use Router\Route;

final class RouteTest extends TestCase
{
    public function testMatchesStaticPathWithSameMethod(): void
    {
        $route = new Route('GET', '/health', static fn() => null);

        self::assertTrue($route->matches('GET', '/health'));
        self::assertFalse($route->matches('GET', '/health/check'));
    }

    public function testDoesNotMatchWhenMethodDiffers(): void
    {
        $route = new Route('POST', '/users', static fn() => null);

        self::assertFalse($route->matches('GET', '/users'));
    }

    public function testMatchesDynamicPathAndExtractsSingleParam(): void
    {
        $route = new Route('GET', '/users/:id', static fn() => null);

        self::assertTrue($route->matches('GET', '/users/42'));
        self::assertSame(['id' => '42'], $route->extractParams('/users/42'));
    }

    public function testExtractsMultipleParamsFromDynamicPath(): void
    {
        $route = new Route('GET', '/users/:userId/posts/:post_id', static fn() => null);

        self::assertTrue($route->matches('GET', '/users/7/posts/99'));
        self::assertSame(
            ['userId' => '7', 'post_id' => '99'],
            $route->extractParams('/users/7/posts/99'),
        );
    }

    public function testExtractParamsReturnsEmptyArrayWhenPathDoesNotMatch(): void
    {
        $route = new Route('GET', '/users/:id', static fn() => null);

        self::assertFalse($route->matches('GET', '/users'));
        self::assertSame([], $route->extractParams('/users'));
    }

    public function testNormalizesRoutePathWithTrailingSlashes(): void
    {
        $route = new Route('GET', '/users/:id/', static fn() => null);

        self::assertTrue($route->matches('GET', '/users/123'));
        self::assertSame(['id' => '123'], $route->extractParams('/users/123'));
    }
}
