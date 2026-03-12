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
- [Seguridad](#seguridad)
  - [Descripción General](#descripción-general)
  - [SecurityChain](#securitychain)
  - [Definición de Reglas](#definición-de-reglas)
  - [Coincidencia de Patrones de Ruta](#coincidencia-de-patrones-de-ruta)
  - [Políticas de Acceso](#políticas-de-acceso)
  - [Respuestas Personalizadas para 401 y 403](#respuestas-personalizadas-para-401-y-403)
  - [UserResolverInterface](#userresolverinterface)
  - [Resolvers Integrados](#resolvers-integrados)
    - [JwtUserResolver](#jwtuserresolver)
    - [SessionUserResolver](#sessionuserresolver)
    - [ChainUserResolver](#chainuserresolver)
  - [AuthenticatedUser](#authenticateduser)
  - [Acceder al Usuario en los Handlers](#acceder-al-usuario-en-los-handlers)
  - [Ejemplo Completo](#ejemplo-completo)
  - [Flujo de Ejecución](#flujo-de-ejecución)
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

## Seguridad

### Descripción General

`vanilla-router` incluye una capa de seguridad declarativa inspirada en el **`SecurityFilterChain`
de Spring Security**. Permite definir políticas de acceso por ruta en una única cadena fluida
que se conecta al router como middleware global estándar — nunca es necesario modificar las
definiciones de rutas existentes.

Propiedades clave:

- **Seguro por defecto (fail-secure)** — si ninguna regla coincide con la ruta entrante, la
  petición es denegada (401).
- **Primera coincidencia gana** — las reglas se evalúan en orden de registro; la primera que
  coincide determina el resultado.
- **Sin acoplamiento** — `SecurityChain` implementa `MiddlewareInterface` y se registra con
  `$router->use()` como cualquier otro middleware.
- **Propagación de request inmutable** — el usuario resuelto se adjunta al request mediante
  el patrón clone/wither (`withUser()`), sin mutar el objeto original.
- **Sin dependencias externas** — la verificación JWT se realiza internamente usando sólo las
  funciones nativas `hash_hmac` y `hash_equals` de PHP.

---

### SecurityChain

`Router\Security\SecurityChain` es la clase principal. Se crea con la fábrica estática y
recibe una implementación de `UserResolverInterface`:

```php
use Router\Security\SecurityChain;
use Router\Resolvers\JwtUserResolver;

$security = SecurityChain::configure(
    new JwtUserResolver(secret: $_ENV['JWT_SECRET'])
);

$router->use($security);
```

Todos los métodos de registro de reglas devuelven la instancia de `SecurityChain`, lo que
permite encadenarlos de forma fluida.

---

### Definición de Reglas

Usa `SecurityChain::path(string $pattern)` para iniciar una regla. Devuelve un `RuleBuilder`
cuyos métodos terminales registran la regla y retornan la cadena:

```php
$security = SecurityChain::configure($userResolver)
    ->path('/login')->permitAll()
    ->path('/register')->permitAll()
    ->path('/admin/*')->hasRole('admin')
    ->path('/api/*')->authenticated()
    ->path('/*')->authenticated();
```

> Las reglas se evalúan **de arriba hacia abajo**. Coloca los patrones más específicos antes
> que los comodines.

---

### Coincidencia de Patrones de Ruta

Se admiten tres modos de coincidencia:

| Modo              | Ejemplo          | Coincide con                                        |
| ----------------- | ---------------- | --------------------------------------------------- |
| Exacto            | `/login`         | Solo `/login`                                       |
| Comodín sufijo    | `/admin/*`       | `/admin/`, `/admin/usuarios`, `/admin/x/y/z`        |
| Parámetro nombrado | `/users/:id`    | `/users/42`, `/users/abc`                           |

---

### Políticas de Acceso

| Método del builder          | Política                                                                |
| --------------------------- | ----------------------------------------------------------------------- |
| `->permitAll()`             | Sin verificación de autenticación; la petición pasa de inmediato.       |
| `->authenticated()`         | La petición debe llevar un usuario autenticado válido (cualquier rol).  |
| `->hasRole('a', 'b')`       | El usuario debe tener **todos** los roles listados (lógica AND).        |
| `->hasAnyRole('a', 'b')`    | El usuario debe tener **al menos uno** de los roles listados (lógica OR). |

```php
SecurityChain::configure($resolver)
    // Rutas públicas
    ->path('/login')->permitAll()
    ->path('/api/v1/token')->permitAll()

    // Área admin — debe tener el rol 'admin'
    ->path('/admin/*')->hasRole('admin')

    // Portal de soporte — 'admin' O 'support'
    ->path('/api/v1/tickets/*')->hasAnyRole('admin', 'support')

    // Todo lo demás — cualquier usuario autenticado
    ->path('/*')->authenticated();
```

---

### Respuestas Personalizadas para 401 y 403

Por defecto, la cadena responde según si la petición parece ser una llamada API/XHR o una
petición normal de navegador:

| Condición           | API / XHR                                             | Navegador          |
| ------------------- | ----------------------------------------------------- | ------------------ |
| No autenticado      | `{"error":true,"message":"Unauthenticated."} 401`     | Redirección `/login` |
| Rol insuficiente    | `{"error":true,"message":"Forbidden..."} 403`         | Redirección `/`    |

Ambos comportamientos se pueden reemplazar con `onUnauthorized()` y `onForbidden()`:

```php
use Router\Request;
use Router\Response;

$security = SecurityChain::configure($resolver)
    ->path('/*')->authenticated()
    ->onUnauthorized(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Por favor, inicia sesión.'], 401);
            return;
        }
        $res->redirect('/login');
    })
    ->onForbidden(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Acceso denegado.'], 403);
            return;
        }
        $res->redirect('/403');
    });
```

---

### UserResolverInterface

`Router\Contracts\UserResolverInterface` es el único punto de integración entre el sistema de
seguridad y el backend de autenticación:

```php
interface UserResolverInterface
{
    public function resolve(Request $request): ?AuthenticatedUser;
}
```

Devuelve un `AuthenticatedUser` cuando las credenciales son válidas, `null` cuando la petición
es anónima o las credenciales están ausentes o son inválidas, o lanza un `HttpException` para
abortar de inmediato con una respuesta HTTP de error.

**Ejemplo de resolver personalizado (API key):**

```php
final class ApiKeyResolver implements UserResolverInterface
{
    public function resolve(Request $request): ?AuthenticatedUser
    {
        $key = $request->header('x-api-key');

        if ($key === null) {
            return null;
        }

        $registro = $this->db->buscarPorApiKey($key);

        if ($registro === null) {
            return null;
        }

        return new AuthenticatedUser(
            id:    (string) $registro['id'],
            name:  $registro['nombre'],
            roles: $registro['roles'],
        );
    }
}
```

---

### Resolvers Integrados

#### JwtUserResolver

`Router\Resolvers\JwtUserResolver` verifica **tokens JWT Bearer HS256** sin ninguna librería
externa. Lee `Authorization: Bearer <token>`, verifica la firma HMAC-SHA256, comprueba el
claim `exp` y mapea los claims estándar a `AuthenticatedUser`:

| Claim JWT | Mapea a                      |
| --------- | ----------------------------- |
| `sub`     | `AuthenticatedUser::$id`     |
| `name`    | `AuthenticatedUser::$name`   |
| `roles`   | `AuthenticatedUser::$roles`  |
| otros     | `AuthenticatedUser::$extra`  |

```php
use Router\Resolvers\JwtUserResolver;

$resolver = new JwtUserResolver(
    secret:    $_ENV['JWT_SECRET'],
    algorithm: 'HS256',  // valor por defecto — actualmente solo HS256 es soportado
);
```

Devuelve `null` ante cualquier fallo: cabecera ausente, token malformado, firma inválida o
token expirado.

---

#### SessionUserResolver

`Router\Resolvers\SessionUserResolver` lee los datos de autenticación desde `$_SESSION`.
Inicia la sesión automáticamente si aún no ha sido iniciada.

Estructura esperada en la sesión:

```php
$_SESSION['auth'] = [
    'id'    => '42',
    'name'  => 'Alicia',
    'roles' => ['editor'],
    // cualquier campo adicional se pasa a AuthenticatedUser::$extra
];
```

```php
use Router\Resolvers\SessionUserResolver;

// La clave de sesión por defecto es 'auth'
$resolver = new SessionUserResolver(sessionKey: 'auth');
```

Devuelve `null` si la clave de sesión está ausente o si `id`/`name` faltan.

---

#### ChainUserResolver

`Router\Resolvers\ChainUserResolver` es un **resolver compuesto** que prueba cada delegado en
orden y devuelve el primer resultado no nulo. Úsalo para soportar múltiples mecanismos de
autenticación simultáneamente (p. ej. sesión para páginas web y JWT para endpoints de API):

```php
use Router\Resolvers\ChainUserResolver;
use Router\Resolvers\SessionUserResolver;
use Router\Resolvers\JwtUserResolver;

$resolver = new ChainUserResolver(
    new SessionUserResolver(sessionKey: 'auth'),
    new JwtUserResolver(secret: $_ENV['JWT_SECRET']),
);
```

---

### AuthenticatedUser

`Router\Security\AuthenticatedUser` es un objeto de valor inmutable que representa a un
usuario autenticado exitosamente.

```php
$user->getId();                          // string — identificador único
$user->getName();                        // string — nombre de visualización
$user->getRoles();                       // string[] — slugs de roles
$user->hasRole('admin');                 // bool — verificación exacta de un rol
$user->hasAnyRole('admin', 'editor');    // bool — verificación OR
$user->get('departamento', 'sin asignar'); // mixed — claim extra con valor por defecto
```

---

### Acceder al Usuario en los Handlers

Cuando una petición llega a un handler protegido, el usuario está garantizado como no nulo:

```php
$router->get('/dashboard', static function (Request $req, Response $res): void {
    $user = $req->user(); // AuthenticatedUser — siempre disponible dentro de una ruta protegida
    $res->html('<h1>Bienvenido, ' . $user->getName() . '</h1>');
});

$router->get('/api/v1/perfil', static function (Request $req, Response $res): void {
    $user = $req->user();
    $res->json([
        'id'     => $user->getId(),
        'nombre' => $user->getName(),
        'roles'  => $user->getRoles(),
    ]);
});
```

También puedes verificar el estado de autenticación en rutas no cubiertas por una regla de
seguridad:

```php
if ($req->isAuthenticated()) {
    // $req->user() no es null
}
```

---

### Ejemplo Completo

```php
<?php

require 'vendor/autoload.php';

use Router\Router;
use Router\Request;
use Router\Response;
use Router\Security\SecurityChain;
use Router\Resolvers\ChainUserResolver;
use Router\Resolvers\SessionUserResolver;
use Router\Resolvers\JwtUserResolver;

// 1. Construir el resolver: primero sesión, luego token JWT Bearer
$resolver = new ChainUserResolver(
    new SessionUserResolver(sessionKey: 'auth'),
    new JwtUserResolver(secret: $_ENV['JWT_SECRET'] ?? 'cambiar-en-produccion'),
);

// 2. Declarar la cadena de seguridad — reglas de primera coincidencia, de arriba a abajo
$security = SecurityChain::configure($resolver)
    ->path('/login')->permitAll()
    ->path('/register')->permitAll()
    ->path('/api/v1/token')->permitAll()
    ->path('/admin/*')->hasRole('admin')
    ->path('/api/v1/tickets/*')->hasAnyRole('admin', 'support')
    ->path('/api/*')->authenticated()
    ->path('/*')->authenticated()
    ->onUnauthorized(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'No autenticado.'], 401);
            return;
        }
        $res->redirect('/login');
    })
    ->onForbidden(static function (Request $req, Response $res): void {
        if ($req->isJson() || $req->isXhr()) {
            $res->json(['error' => true, 'message' => 'Prohibido.'], 403);
            return;
        }
        $res->redirect('/');
    });

// 3. Registrar en el router — una línea asegura toda la aplicación
$router = new Router();
$router->use($security);

// 4. Definir rutas — sin lógica de autenticación dentro de los handlers
$router->get('/login', static function (Request $req, Response $res): void {
    $res->html('<form method="post" action="/login">...</form>');
});

$router->get('/admin/dashboard', static function (Request $req, Response $res): void {
    $user = $req->user(); // garantizado no nulo — SecurityChain aplicó hasRole('admin')
    $res->html('<h1>Panel de Administración — ' . $user->getName() . '</h1>');
});

$router->get('/api/v1/me', static function (Request $req, Response $res): void {
    $user = $req->user();
    $res->json([
        'id'     => $user->getId(),
        'nombre' => $user->getName(),
        'roles'  => $user->getRoles(),
    ]);
});

$router->dispatch();
```

---

### Flujo de Ejecución

```
$router->use($securityChain)
         │
         ▼
SecurityChain::handle(Request, Response, $next)
         │
         ├─ 1. Buscar la primera SecurityRule cuyo patrón coincida con la ruta
         │        │
         │        └─ Sin coincidencia → denegar 401 (fail-secure)
         │
         ├─ 2. ¿La política de la regla es PERMIT_ALL?
         │        └─ Sí → llamar $next($request) inmediatamente, sin verificación de auth
         │
         ├─ 3. Llamar UserResolverInterface::resolve($request)
         │        │
         │        └─ Devuelve null → denegar 401 (no autenticado)
         │
         ├─ 4. Adjuntar usuario: $request = $request->withUser($user)  [clon inmutable]
         │
         ├─ 5. ¿El usuario satisface la política de la regla?
         │        │  AUTHENTICATED  → siempre sí
         │        │  HAS_ROLE       → el usuario debe tener TODOS los roles listados
         │        │  HAS_ANY_ROLE   → el usuario debe tener AL MENOS UNO de los roles
         │        │
         │        └─ No → denegar 403 (prohibido)
         │
         └─ 6. Llamar $next($authenticatedRequest)
                  └─ El handler de la ruta se ejecuta; $req->user() devuelve AuthenticatedUser
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
