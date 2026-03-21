<?php

declare(strict_types=1);

namespace Router\Tests;

use PHPUnit\Framework\TestCase;
use Router\Request;

final class RequestUriEndpointsTest extends TestCase
{
    public function testQueryReturnsExpectedValuesAndDefault(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/search',
            queryParams: ['q' => 'router', 'limit' => '25'],
            bodyParams: [],
            headers: ['accept' => 'application/json'],
            files: [],
            rawBody: '',
        );

        self::assertSame('router', $request->query('q'));
        self::assertSame('25', $request->query('limit'));
        self::assertSame('10', $request->query('page', '10'));
    }

    public function testBodyReturnsExpectedValuesAndDefault(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/users',
            queryParams: [],
            bodyParams: [
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
            ],
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
            files: [],
            rawBody: '',
        );

        self::assertSame('Ada Lovelace', $request->body('name'));
        self::assertSame('ada@example.com', $request->body('email'));
        self::assertSame('guest', $request->body('role', 'guest'));
    }

    public function testParamReturnsInjectedRouteValuesAndDefault(): void
    {
        $request = new Request(
            method: 'GET',
            path: '/users/42',
            queryParams: [],
            bodyParams: [],
            headers: ['accept' => 'application/json'],
            files: [],
            rawBody: '',
        );

        $requestWithParams = $request->withRouteParams([
            'id' => '42',
            'postId' => '99',
        ]);

        self::assertSame('42', $requestWithParams->param('id'));
        self::assertSame('99', $requestWithParams->param('postId'));
        self::assertSame('n/a', $requestWithParams->param('slug', 'n/a'));
    }

    public function testRequestCanCombineBodyQueryAndParamAccess(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/users/7',
            queryParams: ['includePosts' => 'true'],
            bodyParams: ['name' => 'Grace Hopper'],
            headers: ['content-type' => 'application/x-www-form-urlencoded'],
            files: [],
            rawBody: '',
        );

        $requestWithParams = $request->withRouteParams(['id' => '7']);

        self::assertSame('7', $requestWithParams->param('id'));
        self::assertSame('true', $requestWithParams->query('includePosts'));
        self::assertSame('Grace Hopper', $requestWithParams->body('name'));
    }
}
