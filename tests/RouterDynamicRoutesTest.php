<?php

declare(strict_types=1);

namespace Router\Tests;

use PHPUnit\Framework\TestCase;
use Router\Request;
use Router\Response;
use Router\Router;

final class RouterDynamicRoutesTest extends TestCase
{
    public function testDynamicRouteInjectsSingleParamIntoRequest(): void
    {
        $router = new Router();

        $router->get('/users/:id', static function (Request $request, Response $response): void {
            $response->json(['id' => $request->param('id')]);
        });

        $request = new Request(
            method: 'GET',
            path: '/users/42',
            queryParams: [],
            bodyParams: [],
            headers: ['accept' => 'application/json'],
            files: [],
            rawBody: '',
        );

        $response = new Response();

        ob_start();
        $router->run($request, $response);
        $payload = ob_get_clean();

        self::assertSame('{"id":"42"}', $payload);
    }

    public function testDynamicRouteInjectsMultipleParamsIntoRequest(): void
    {
        $router = new Router();

        $router->get('/users/:id/posts/:postId', static function (Request $request, Response $response): void {
            $response->json([
                'id' => $request->param('id'),
                'postId' => $request->param('postId'),
            ]);
        });

        $request = new Request(
            method: 'GET',
            path: '/users/42/posts/99',
            queryParams: [],
            bodyParams: [],
            headers: ['accept' => 'application/json'],
            files: [],
            rawBody: '',
        );

        $response = new Response();

        ob_start();
        $router->run($request, $response);
        $payload = ob_get_clean();

        self::assertSame('{"id":"42","postId":"99"}', $payload);
    }

    public function testDynamicPathWithMethodMismatchReturns405JsonError(): void
    {
        $router = new Router();

        $router->get('/users/:id', static function (Request $request, Response $response): void {
            $response->json(['id' => $request->param('id')]);
        });

        $request = new Request(
            method: 'POST',
            path: '/users/42',
            queryParams: [],
            bodyParams: [],
            headers: ['accept' => 'application/json'],
            files: [],
            rawBody: '',
        );

        $response = new Response();

        ob_start();
        $router->run($request, $response);
        $payload = ob_get_clean();

        self::assertStringContainsString('Method POST is not allowed on /users/42', $payload);
        self::assertStringContainsString('Allowed: GET', $payload);
    }
}
