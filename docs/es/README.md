# vanilla-router — Documentación

Un enrutador HTTP ligero y sin dependencias para PHP 8.1+, inspirado en Express.js.

> [English Documentation](../en/README.md)

---

## Tabla de Contenidos

- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Inicio Rápido](#inicio-rápido)
- [Router](#router)
  - [Registro de Rutas](#registro-de-rutas)
  - [Parámetros de Ruta](#parámetros-de-ruta)
  - [Middleware Global](#middleware-global)
  - [Manejo de Errores](#manejo-de-errores)
  - [Despacho](#despacho)
- [Request](#request)
- [Response](#response)
- [Middleware](#middleware)
- [HttpException](#httpexception)
- [Arquitectura](#arquitectura)

---

## Requisitos

- PHP >= 8.1

## Instalación

```bash
composer require alberto-cruz-mtz/vanilla-router
```

---

## Inicio Rápido

```php
<?php

require 'vendor/autoload.php';

use Router\Router;
use Router\Request;
use Router\Response;

$router = new Router();

$router->get('/', static function (Request $req, Response $res): void {
    $res->html('<h1>¡Hola, Mundo!</h1>');
});

$router->get('/usuarios/:id', static function (Request $req, Response $res): void {
    $id = $req->param('id');
    $res->json(['id' => $id, 'nombre' => 'Juan Pérez']);
});

$router->post('/usuarios', static function (Request $req, Response $res): void {
    $nombre = $req->body('nombre');
    $res->json(['creado' => true, 'nombre' => $nombre], 201);
});

$router->dispatch();
```

---

## Router

`Router\Router` es el punto de entrada principal. Registra rutas, conecta middleware y despacha
peticiones HTTP entrantes a través de toda la cadena de middlewares.

### Registro de Rutas

Todos los métodos de registro devuelven `self`, lo que permite encadenarlos de forma fluida.

| Método                                                              | Verbo(s) HTTP                                          |
| ------------------------------------------------------------------- | ------------------------------------------------------ |
| `get(string $path, callable\|array $handler, array $mw = [])`      | GET                                                    |
| `post(string $path, callable\|array $handler, array $mw = [])`     | POST                                                   |
| `put(string $path, callable\|array $handler, array $mw = [])`      | PUT                                                    |
| `patch(string $path, callable\|array $handler, array $mw = [])`    | PATCH                                                  |
| `delete(string $path, callable\|array $handler, array $mw = [])`   | DELETE                                                 |
| `any(string $path, callable\|array $handler, array $mw = [])`      | GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD           |

**Handler con clausura:**

```php
$router->get('/hola', static function (Request $req, Response $res): void {
    $res->text('¡Hola!');
});
```

**Handler basado en clase** (invocable o `[NombreClase::class, 'metodo']`):

```php
// Clase invocable
class HomeController {
    public function __invoke(Request $req, Response $res): void {
        $res->html('<h1>Inicio</h1>');
    }
}

$router->get('/', HomeController::class);

// Referencia a método
class UsuarioController {
    public function mostrar(Request $req, Response $res): void {
        $res->json(['id' => $req->param('id')]);
    }
}

$router->get('/usuarios/:id', [UsuarioController::class, 'mostrar']);
```

**Encadenamiento:**

```php
$router
    ->get('/usuarios',       [UsuarioController::class, 'index'])
    ->post('/usuarios',      [UsuarioController::class, 'guardar'])
    ->get('/usuarios/:id',   [UsuarioController::class, 'mostrar'])
    ->put('/usuarios/:id',   [UsuarioController::class, 'actualizar'])
    ->delete('/usuarios/:id', [UsuarioController::class, 'eliminar']);
```

### Parámetros de Ruta

Define segmentos dinámicos con la sintaxis `:param`. Los valores se obtienen con `$req->param()`.

```php
$router->get('/posts/:slug/comentarios/:id', static function (Request $req, Response $res): void {
    $slug = $req->param('slug');
    $id   = $req->param('id');
    $res->json(['slug' => $slug, 'comentarioId' => $id]);
});
```

### Middleware Global

Usa `Router::use()` para conectar middleware que se ejecute en cada petición que coincida con
una ruta.

```php
use Router\Contracts\MiddlewareInterface;
use Router\Request;
use Router\Response;

final class AutenticacionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        $token = $request->header('authorization');

        if ($token === null) {
            throw \Router\Exceptions\HttpException::unauthorized();
        }

        $next($request, $response);
    }
}

$router->use(new AutenticacionMiddleware());
```

El middleware por ruta se pasa como tercer argumento en el método de registro:

```php
$router->get('/admin', [AdminController::class, 'index'], [new AutenticacionMiddleware()]);
```

### Manejo de Errores

Por defecto, las excepciones no capturadas son gestionadas por `DefaultErrorHandler`, que detecta
si debe responder en JSON o HTML y oculta los stack traces a menos que `APP_DEBUG=true`.

Para personalizarlo, implementa `ErrorHandlerInterface` y llama a `setErrorHandler()`:

```php
use Router\Contracts\ErrorHandlerInterface;
use Router\Request;
use Router\Response;

final class MiManejadorDeErrores implements ErrorHandlerInterface
{
    public function handle(\Throwable $throwable, Request $request, Response $response): void
    {
        $response->json([
            'error'   => true,
            'mensaje' => $throwable->getMessage(),
        ], 500);
    }
}

$router->setErrorHandler(new MiManejadorDeErrores());
```

### Despacho

```php
// Producción: lee automáticamente los superglobales de PHP
$router->dispatch();

// Pruebas: pasa un par Request/Response pre-construido
$request  = Request::fromGlobals();
$response = new Response();
$router->run($request, $response);
```

---

## Request

`Router\Request` es un wrapper inmutable sobre los superglobales de PHP. Constrúyelo con la
fábrica estática:

```php
$request = Request::fromGlobals();
```

### Métodos

#### Información HTTP

```php
$request->getMethod(); // 'GET', 'POST', ...
$request->getPath();   // '/usuarios/42'
```

#### Query string (`$_GET`)

```php
$request->query('pagina', 1); // valor único, saneado; valor por defecto = 1
$request->allQuery();          // todos los parámetros de consulta, saneados
```

#### Cuerpo (`$_POST`)

```php
$request->body('email');  // valor único, saneado
$request->allBody();       // todos los parámetros del cuerpo, saneados
```

#### Parámetros de ruta (`:param`)

```php
$request->param('id');   // valor extraído del patrón de URL
$request->allParams();   // todos los parámetros de ruta como array
```

#### Cabeceras

```php
$request->header('content-type');              // búsqueda insensible a mayúsculas
$request->header('x-api-key', 'por-defecto');  // con valor por defecto
```

#### Archivos

```php
$request->getFiles(); // array $_FILES sin modificar
```

#### Cuerpo JSON

```php
if ($request->isJson()) {
    $datos = $request->json(); // array decodificado; lanza \JsonException si es JSON inválido
}
```

#### Detección XHR

```php
$request->isXhr(); // true cuando X-Requested-With: XMLHttpRequest
```

> **Nota:** `query()`, `body()` y `param()` sanean los valores de cadena con
> `htmlspecialchars(trim(...), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` y recorren los arrays
> recursivamente.

---

## Response

`Router\Response` es un constructor fluido que acumula estado, cabeceras y cuerpo antes de
emitirlo todo de una vez mediante `send()`. Todos los métodos de cuerpo llaman a `send()` internamente.

### Estado

```php
$response->status(201);       // devuelve self para encadenamiento
$response->getStatusCode();   // int
```

### Cabeceras

```php
$response->withHeader('X-Custom', 'valor');                    // cabecera individual
$response->withHeaders(['X-Foo' => 'a', 'X-Bar' => 'b']);     // varias cabeceras
```

### Helpers de cuerpo

```php
// HTML
$response->html('<h1>Hola</h1>');
$response->html('<h1>Creado</h1>', 201);

// Archivo de vista PHP
$response->view('/ruta/a/vista.php', ['nombre' => 'Alicia']);

// JSON
$response->json(['clave' => 'valor']);
$response->json(['clave' => 'valor'], 201);

// Envelope de error JSON  { error: true, message: '...', details: ... }
$response->jsonError('Falló la validación', 422, ['campo' => 'email']);

// Texto plano
$response->text('OK');

// Redirección
$response->redirect('/login');
$response->redirect('/dashboard', 301);
```

### Emisión manual

```php
$response
    ->status(200)
    ->withHeader('Content-Type', 'text/plain')
    ->send(); // idempotente — llamar a send() una segunda vez no tiene efecto
```

---

## Middleware

Implementa `Router\Contracts\MiddlewareInterface`:

```php
interface MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void;
}
```

Llama a `$next($request, $response)` para continuar la cadena. No llamarlo cortocircuita la
ejecución e impide que el handler de la ruta se ejecute.

**Ejemplo — logger de peticiones:**

```php
final class LoggerMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        error_log($request->getMethod() . ' ' . $request->getPath());
        $next($request, $response);
    }
}
```

**Ejemplo — CORS:**

```php
final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Response $response, callable $next): void
    {
        $response->withHeader('Access-Control-Allow-Origin', '*');

        if ($request->getMethod() === 'OPTIONS') {
            $response->status(204)->send();
            return;
        }

        $next($request, $response);
    }
}
```

---

## HttpException

`Router\Exceptions\HttpException` extiende `\RuntimeException` y lleva un código de estado HTTP.
Lánzala desde cualquier handler o middleware — el router la capturará y la delegará al manejador
de errores.

**Constructores con nombre:**

```php
use Router\Exceptions\HttpException;

throw HttpException::notFound();                              // 404
throw HttpException::methodNotAllowed();                      // 405
throw HttpException::unauthorized();                          // 401
throw HttpException::forbidden();                             // 403
throw HttpException::badRequest('Entrada inválida');          // 400
throw HttpException::unprocessableEntity('Falló validación'); // 422
throw HttpException::internalServerError('Error interno');    // 500
```

**Código de estado personalizado:**

```php
throw new HttpException(429, 'Demasiadas Peticiones');
```

---

## Arquitectura

| Decisión                   | Detalle                                                                              |
| -------------------------- | ------------------------------------------------------------------------------------ |
| **Sin PSR-7**              | `Request`/`Response` son wrappers delgados sobre superglobales — intencionalmente simples. |
| **Sin contenedor DI**      | Los handlers se instancian con `new $class()`. No se introduce ningún contenedor.    |
| **Sin dependencias**       | PHP 8.1+ puro. Nada en `require` más allá del runtime de PHP.                        |
| **Enrutamiento por regex** | Los patrones `:param` se compilan a regex de captura nombrada al registrar la ruta.  |
| **Tipos estrictos**        | Cada archivo comienza con `declare(strict_types=1);`.                                |
| **Inmutabilidad**          | `Request` y `Route` son inmutables; se usa el patrón clone/wither en toda la base.  |
