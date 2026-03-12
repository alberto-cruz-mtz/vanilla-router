# vanilla-router

A lightweight, zero-dependency HTTP router for PHP 8.1+, inspired by Express.js.

> **Documentation:**
> - [English documentation](docs/en/README.md)
> - [Documentación en Español](docs/es/README.md)

---

## Requirements

- PHP >= 8.1

## Installation

```bash
composer require alberto-cruz-mtz/vanilla-router
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Router\Router;
use Router\Request;
use Router\Response;

$router = new Router();

$router->get('/', static function (Request $req, Response $res): void {
    $res->json(['message' => 'Hello, World!']);
});

$router->get('/users/:id', static function (Request $req, Response $res): void {
    $id = $req->param('id');
    $res->json(['id' => $id]);
});

$router->dispatch();
```

## License

MIT — [jose alberto cruz martinez](mailto:albertocruz8133@proton.me)
