# Rol Super Admin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `super_admin` role that sits above `admin` in a strict hierarchy — a `super_admin` can do everything an `admin` can, plus exclusively manage Usuarios and the catálogos (Puestos, Períodos, Feriados) under `/mantenimientos`, while a plain `admin` loses access to that group.

**Architecture:** `RoleMiddleware` changes from an exact string match to a numeric-rank comparison (`empleado=0, admin=1, super_admin=2`), so every existing `RoleMiddleware('admin')` route group keeps working unchanged and automatically admits a `super_admin` too. Only the `/mantenimientos` route group is re-pointed to `RoleMiddleware('super_admin')`. `UsuariosService` gets one new invariant: the system may never end up with zero active `super_admin` accounts.

**Tech Stack:** PHP 8.1 / Slim 4 / PDO raw / Twig 3 / PHPUnit 11 (existing stack, no new dependencies).

**Spec:** `docs/superpowers/specs/2026-09-13-rbac-super-admin-design.md`

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file touched.
- Controllers never touch PDO directly; Repositories never contain business logic (from `CLAUDE.md`).
- Controllers and `RoleMiddleware` read the authenticated user only via `$request->getAttribute('usuario')`, never `$_SESSION['usuario_*']` directly (exception: `AuthController` — untouched by this plan).
- DB identifiers stay `snake_case`; PHP classes `PascalCase`, methods `camelCase`.
- No ORM, no new Composer packages — this feature is achievable with the existing stack.
- Role name in code and DB: `super_admin` (snake_case, matches existing ENUM value style).

---

### Task 1: Schema and seed data — add `super_admin`

**Files:**
- Modify: `database/schema.sql:36-52`
- Modify: `database/seed.sql:8-14`

**Interfaces:**
- Produces: `usuarios.rol` ENUM accepts `'super_admin'` as a value. `id_usuario=2` (`superadmin` / `super_admin`, active) becomes the seeded super_admin — this is the same row the existing seeded `empleados` row (`Emerson`) already points to via `id_usuario=2`, so that FK is untouched. `id_usuario=3` (`jperez`) is unchanged. A new `id_usuario=4` (`admin` / `admin`, active) is added for day-to-day testing. All three still use the existing test password `password123`.

This task has no PHPUnit coverage — the project has no DB-integration test suite (confirmed: no `tests/Repositories` directory exists, and Repository methods like `hasLinkedEmpleado` have no dedicated tests either). Verification is the manual DB reimport step below, matching the project's own established "pendiente prueba manual" workflow.

- [ ] **Step 0: Create the feature branch**

Per `CLAUDE.md`'s Gitflow convention (`feature/*` → `develop` → `main`), this work happens on its own branch, cut from the current `develop`:

Run: `git checkout -b feature/super-admin`
Expected: `Switched to a new branch 'feature/super-admin'`. Any pre-existing uncommitted changes in the working tree (unrelated work already in progress) move to the new branch untouched — this plan's commits below only ever `git add` the specific files each task lists, so they won't sweep those unrelated changes in.

- [ ] **Step 1: Update the ENUM and its comment in `database/schema.sql`**

Replace lines 36-47:

```sql
-- 2. usuarios
-- Credenciales y roles de acceso al sistema (super_admin / admin / empleado).
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id_usuario`      INT            NOT NULL AUTO_INCREMENT,
    `nombre_usuario`  VARCHAR(80)    NOT NULL,
    `contrasena_hash` VARCHAR(255)   NOT NULL,
    `rol`             ENUM('super_admin','admin','empleado') NOT NULL DEFAULT 'empleado',
    `activo`          TINYINT(1)     NOT NULL DEFAULT 1,
    `fecha_creacion`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_usuario`),
    UNIQUE KEY `uq_usuarios_nombre` (`nombre_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Leave the sentinel INSERT right below it (`id_usuario=1`, `sin_cuenta`, `rol='empleado'`, inactive) exactly as-is.

- [ ] **Step 2: Update `database/seed.sql`**

Replace lines 12-14:

```sql
INSERT INTO `usuarios` (`id_usuario`, `nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
(2, 'superadmin', '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'super_admin', 1),
(3, 'jperez',     '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'empleado',    1),
(4, 'admin',      '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'admin',       1);
```

- [ ] **Step 3: Reimport locally and verify**

In phpMyAdmin (or CLI): drop/recreate `lubrimotos_nomina`, then import `database/schema.sql` followed by `database/seed.sql`. Confirm both import with zero SQL errors, and that:

```sql
SELECT id_usuario, nombre_usuario, rol, activo FROM usuarios;
```

returns exactly 4 rows: `1/sin_cuenta/empleado/0`, `2/superadmin/super_admin/1`, `3/jperez/empleado/1`, `4/admin/admin/1`.

- [ ] **Step 4: Commit**

```bash
git add database/schema.sql database/seed.sql
git commit -m "feat(rbac): add super_admin role to usuarios schema and seed data"
```

---

### Task 2: `RoleMiddleware` hierarchy + re-point `/mantenimientos`

**Files:**
- Modify: `src/Middleware/RoleMiddleware.php`
- Modify: `config/routes.php:38,75`
- Test: `tests/Middleware/RoleMiddlewareTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 at the code level (tests use in-memory request attributes, not the DB).
- Produces: `RoleMiddleware` now grants access when `rank(usuario.rol) >= rank(required)` instead of exact equality. Every other route group in `config/routes.php` that already does `->add(new RoleMiddleware('admin'))` keeps working unchanged and now also admits `super_admin` — no other task needs to touch them.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Middleware/RoleMiddlewareTest.php` (inside the `RoleMiddlewareTest` class, after the existing two test methods):

```php
    public function testUnRolSuperiorPasaUnaBarreraDeRolInferior(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/empleados')
            ->withAttribute('usuario', ['id' => 2, 'nombre' => 'superadmin', 'rol' => 'super_admin']);

        $handlerInvocado = false;
        $handler = new class ($handlerInvocado) implements RequestHandlerInterface {
            public function __construct(private bool &$handlerInvocado) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->handlerInvocado = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };

        // Una barrera 'admin' debe dejar pasar a un super_admin (rango mayor).
        $response = (new RoleMiddleware('admin'))->process($request, $handler);

        self::assertTrue($handlerInvocado);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testUnRolInferiorNoPasaUnaBarreraDeRolSuperior(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/mantenimientos/usuarios')
            ->withAttribute('usuario', ['id' => 4, 'nombre' => 'admin', 'rol' => 'admin']);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                self::fail('El handler no debía ejecutarse: admin no alcanza el rango de super_admin.');
            }
        };

        // Mismo motivo que el test de denegación existente: RouteContext exige
        // routing ya resuelto, fuera del alcance de un test unitario.
        $this->expectException(\RuntimeException::class);

        (new RoleMiddleware('super_admin'))->process($request, $handler);
    }

    public function testUnRolDesconocidoNoPasaNingunaBarrera(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/empleados')
            ->withAttribute('usuario', ['id' => 99, 'nombre' => 'fantasma', 'rol' => '']);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                self::fail('El handler no debía ejecutarse: rol vacío no tiene rango.');
            }
        };

        $this->expectException(\RuntimeException::class);

        (new RoleMiddleware('admin'))->process($request, $handler);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `vendor/bin/phpunit tests/Middleware/RoleMiddlewareTest.php`
Expected: the two pre-existing tests PASS; `testUnRolSuperiorPasaUnaBarreraDeRolInferior` FAILS (current code does exact match, so `'super_admin' !== 'admin'` denies access and the handler is never invoked); `testUnRolInferiorNoPasaUnaBarreraDeRolSuperior` and `testUnRolDesconocidoNoPasaNingunaBarrera` may already pass by coincidence (exact match also denies these) — that's fine, they still need to hold after Step 3.

- [ ] **Step 3: Implement the rank-based hierarchy**

Replace the full contents of `src/Middleware/RoleMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

/**
 * RoleMiddleware (RBAC jerárquico)
 *
 * Verifica que el rango del rol autenticado sea igual o mayor al rango
 * requerido (jerarquía: super_admin > admin > empleado). Debe usarse
 * DESPUÉS de AuthMiddleware (lee el atributo 'usuario' que este adjunta
 * al Request; no la sesión directamente).
 *
 * Uso en routes.php:
 *   ->add(new RoleMiddleware('admin'))->add(new AuthMiddleware())
 */
class RoleMiddleware implements MiddlewareInterface
{
    private const RANGOS = [
        'empleado'    => 0,
        'admin'       => 1,
        'super_admin' => 2,
    ];

    private string $rolRequerido;

    public function __construct(string $rolRequerido)
    {
        $this->rolRequerido = $rolRequerido;
    }

    public function process(
        ServerRequestInterface  $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {

        $usuario     = $request->getAttribute('usuario', []);
        $rolUsuario  = $usuario['rol'] ?? '';
        $rangoActual = self::RANGOS[$rolUsuario] ?? -1;
        $rangoMinimo = self::RANGOS[$this->rolRequerido] ?? PHP_INT_MAX;

        if ($rangoActual < $rangoMinimo) {
            // Acceso denegado: redirigir al dashboard con mensaje de error
            $_SESSION['flash_error'] = 'No tiene permisos para acceder a esa sección.';

            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $response = new Response();
            return $response
                ->withHeader('Location', $routeParser->urlFor('dashboard'))
                ->withStatus(302);
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Middleware/RoleMiddlewareTest.php`
Expected: all 5 tests PASS.

- [ ] **Step 5: Re-point `/mantenimientos` to `super_admin`**

In `config/routes.php`, line 38, change the comment:

```php
    // ── Mantenimientos (solo super_admin) ─────────────────
```

And line 75, change the middleware:

```php
    })->add(new RoleMiddleware('super_admin'))->add(new AuthMiddleware());
```

(This is the closing line of the `/mantenimientos` group only — Puestos, Períodos, Feriados, Usuarios. Every other `RoleMiddleware('admin')` call in the file, e.g. line 85 for `/empleados`, stays exactly as-is.)

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures (routes.php isn't unit-tested directly, so this is a safety net for anything the middleware change could have touched indirectly).

- [ ] **Step 7: Commit**

```bash
git add src/Middleware/RoleMiddleware.php config/routes.php tests/Middleware/RoleMiddlewareTest.php
git commit -m "feat(rbac): make RoleMiddleware hierarchical and restrict /mantenimientos to super_admin"
```

---

### Task 3: `UsuariosService` — accept `super_admin` and protect the last active one

**Files:**
- Modify: `src/Repositories/UsuariosRepository.php:92-100`
- Modify: `src/Services/UsuariosService.php`
- Test: `tests/Services/UsuariosServiceSuperAdminTest.php` (new)

**Interfaces:**
- Consumes: none from Task 1/2 at the code level (mocked repository).
- Produces: `UsuariosRepository::contarSuperAdminsActivos(): int` (counts `rol='super_admin' AND activo=1`), used only by `UsuariosService`. `UsuariosService::ROLES_VALIDOS` now includes `'super_admin'`. No other task depends on these.

- [ ] **Step 1: Add the counting method to `UsuariosRepository`**

In `src/Repositories/UsuariosRepository.php`, add this method right after `hasLinkedEmpleado` (after line 99, before the closing `}` of the class):

```php
    public function contarSuperAdminsActivos(): int
    {
        return (int)$this->pdo->query(
            "SELECT COUNT(*) FROM usuarios WHERE rol = 'super_admin' AND activo = 1"
        )->fetchColumn();
    }
```

No dedicated test for this method — the project has no Repository test suite (confirmed: `hasLinkedEmpleado` right above it has none either). It's exercised indirectly through the `UsuariosService` tests below via mocking.

- [ ] **Step 2: Write the failing tests**

Create `tests/Services/UsuariosServiceSuperAdminTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use App\Services\UsuariosService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UsuariosServiceSuperAdminTest extends TestCase
{
    private function service(UsuariosRepository $repo): UsuariosService
    {
        return new UsuariosService($repo, $this->createMock(AuditoriaRepository::class));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function usuarioActual(array $overrides = []): array
    {
        return array_merge([
            'id_usuario'     => 2,
            'nombre_usuario' => 'superadmin',
            'rol'            => 'super_admin',
            'activo'         => 1,
            'fecha_creacion' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function testCrearAceptaRolSuperAdminComoValido(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->expects(self::once())->method('insert')
            ->with('nuevo_super', self::isType('string'), 'super_admin')
            ->willReturn(9);

        $this->service($repo)->crear(
            ['nombre_usuario' => 'nuevo_super', 'rol' => 'super_admin', 'contrasena' => 'password123'],
            1,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarBloqueaDegradarAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'admin', 'activo' => '1'],
            99,
            '127.0.0.1'
        );
    }

    public function testActualizarBloqueaDesactivarAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'super_admin', 'activo' => '0'],
            99,
            '127.0.0.1'
        );
    }

    public function testActualizarPermiteDegradarCuandoHayOtroSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('contarSuperAdminsActivos')->willReturn(2);
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'superadmin', 'rol' => 'admin', 'activo' => '1'],
            99,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarNoConsultaConteoSiElUsuarioActualNoEsSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual(['rol' => 'admin']));
        $repo->expects(self::never())->method('contarSuperAdminsActivos');
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(
            2,
            ['nombre_usuario' => 'admin', 'rol' => 'empleado', 'activo' => '1'],
            99,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testEliminarBloqueaAlUltimoSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('hasLinkedEmpleado')->willReturn(false);
        $repo->method('contarSuperAdminsActivos')->willReturn(1);
        $repo->expects(self::never())->method('delete');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe existir al menos un super_admin activo');

        $this->service($repo)->eliminar(2, 99, '127.0.0.1');
    }

    public function testEliminarPermiteCuandoHayOtroSuperAdminActivo(): void
    {
        $repo = $this->createMock(UsuariosRepository::class);
        $repo->method('findById')->willReturn($this->usuarioActual());
        $repo->method('hasLinkedEmpleado')->willReturn(false);
        $repo->method('contarSuperAdminsActivos')->willReturn(2);
        $repo->expects(self::once())->method('delete');

        $this->service($repo)->eliminar(2, 99, '127.0.0.1');

        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Services/UsuariosServiceSuperAdminTest.php`
Expected: `testCrearAceptaRolSuperAdminComoValido` FAILS ("El rol seleccionado no es válido." thrown instead of `insert` being called — `super_admin` isn't in `ROLES_VALIDOS` yet). The `actualizar`/`eliminar` guard tests FAIL too, but with a PHPUnit *mock configuration* error ("method contarSuperAdminsActivos does not exist") until Step 1 of this task lands — since Step 1 already added the method to the real class before writing these tests, they should instead fail because `UsuariosService` never calls `contarSuperAdminsActivos()` yet, so the "block" tests don't get the expected exception (they FAIL with "Expected exception InvalidArgumentException was not thrown") and `testActualizarNoConsultaConteoSiElUsuarioActualNoEsSuperAdminActivo` passes vacuously.

- [ ] **Step 4: Implement the guard in `UsuariosService`**

In `src/Services/UsuariosService.php`, change line 14:

```php
    private const ROLES_VALIDOS = ['super_admin', 'admin', 'empleado'];
```

Add this private method (e.g., right after `actualizar()`, before `resetearPassword()`):

```php
    private function bloquearSiEsUltimoSuperAdminActivo(array $actual, bool $dejaDeSerSuperAdminActivo): void
    {
        $esSuperAdminActivoHoy = $actual['rol'] === 'super_admin' && (int)$actual['activo'] === 1;

        if ($esSuperAdminActivoHoy && $dejaDeSerSuperAdminActivo && $this->repo->contarSuperAdminsActivos() <= 1) {
            throw new InvalidArgumentException('Debe existir al menos un super_admin activo en el sistema.');
        }
    }
```

In `actualizar()`, right after the existing self-protection block (`if ($id === $loggedInId) { ... }`) and before the `try { $this->repo->update(...) }` block, add:

```php
        $dejaDeSerSuperAdminActivo = ($rol !== 'super_admin') || ($activo !== 1);
        $this->bloquearSiEsUltimoSuperAdminActivo($current, $dejaDeSerSuperAdminActivo);
```

In `eliminar()`, change the first line from `$this->obtener($id);` to capture the row:

```php
        $actual = $this->obtener($id);
```

Then, right after the existing `hasLinkedEmpleado` check and before `$this->repo->delete($id);`, add:

```php
        $this->bloquearSiEsUltimoSuperAdminActivo($actual, true);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Services/UsuariosServiceSuperAdminTest.php`
Expected: all 7 tests PASS.

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures.

- [ ] **Step 7: Commit**

```bash
git add src/Repositories/UsuariosRepository.php src/Services/UsuariosService.php tests/Services/UsuariosServiceSuperAdminTest.php
git commit -m "feat(rbac): let UsuariosService accept super_admin and protect the last active one"
```

---

### Task 4: UI — sidebar, badges, Usuarios form, Dashboard KPIs

**Files:**
- Modify: `templates/layouts/base.html.twig:37,64-70,130`
- Modify: `public/assets/css/app.css:148-153`
- Modify: `templates/usuarios/form.html.twig:54-64`
- Modify: `templates/usuarios/index.html.twig:59-63`
- Modify: `templates/dashboard/index.html.twig:13`
- Modify: `src/Controllers/DashboardController.php:26`

**Interfaces:**
- Consumes: nothing from earlier tasks at the code level (Twig/CSS have no unit tests in this project).
- Produces: nothing consumed by later tasks.

This project has no Twig-rendering or Controller test suite (confirmed: no `tests/Controllers` directory exists anywhere). Verification here is `php -l` for the one PHP file plus a manual QA pass, matching the project's own established convention (every module in `CLAUDE.md` is marked "✅ Completo (pendiente prueba manual)").

- [ ] **Step 1: Sidebar — admit `super_admin` to the admin sidebar, gate "Administración" further**

In `templates/layouts/base.html.twig`, line 37, change:

```twig
{% if session.usuario_rol in ['admin', 'super_admin'] %}
```

Then wrap the existing "Administración" group (lines 64-70) in a nested check, so it only shows for `super_admin`:

```twig
        {% if session.usuario_rol == 'super_admin' %}
        <div class="sb-group">
            <div class="sb-label">Administración</div>
            <a class="sb-item" href="{{ url_for('puestos.index') }}"><i class="bi bi-briefcase"></i><span>Puestos</span></a>
            <a class="sb-item" href="{{ url_for('periodos.index') }}"><i class="bi bi-calendar-range"></i><span>Períodos de Pago</span></a>
            <a class="sb-item" href="{{ url_for('feriados.index') }}"><i class="bi bi-calendar-x"></i><span>Feriados</span></a>
            <a class="sb-item" href="{{ url_for('usuarios.index') }}"><i class="bi bi-person-gear"></i><span>Usuarios</span></a>
        </div>
        {% endif %}
```

(The `{% else %}` branch for the `empleado` portal sidebar, further down, is untouched.)

- [ ] **Step 2: Role badge in the topbar**

In `templates/layouts/base.html.twig`, line 130, change:

```twig
                    <span class="badge-fo {{ session.usuario_rol == 'super_admin' ? 'badge-super' : (session.usuario_rol == 'admin' ? 'badge-admin' : 'badge-emp') }}">
```

- [ ] **Step 3: New badge color in CSS**

In `public/assets/css/app.css`, right after line 151 (`.badge-emp`), add:

```css
.badge-super { background: #f1e6ff; color: #6b21a8; }
```

- [ ] **Step 4: Usuarios form — add the role option**

In `templates/usuarios/form.html.twig`, inside the `<select id="rol">` block (lines 54-64), add a third `<option>` before `admin`:

```twig
                        <select class="form-select" id="rol" name="rol" required>
                            <option value="">— Seleccione —</option>
                            <option value="super_admin"
                                {{ (usuario.rol ?? '') == 'super_admin' ? 'selected' : '' }}>
                                Super Administrador
                            </option>
                            <option value="admin"
                                {{ (usuario.rol ?? '') == 'admin' ? 'selected' : '' }}>
                                Administrador
                            </option>
                            <option value="empleado"
                                {{ (usuario.rol ?? '') == 'empleado' ? 'selected' : '' }}>
                                Empleado
                            </option>
                        </select>
```

- [ ] **Step 5: Usuarios list — three-way role badge per row**

In `templates/usuarios/index.html.twig`, replace lines 59-63:

```twig
                        {% if u.rol == 'super_admin' %}
                            <span class="badge bg-dark">Super Admin</span>
                        {% elseif u.rol == 'admin' %}
                            <span class="badge bg-danger">Admin</span>
                        {% else %}
                            <span class="badge bg-secondary">Empleado</span>
                        {% endif %}
```

- [ ] **Step 6: Dashboard KPI gate**

In `templates/dashboard/index.html.twig`, line 13, change:

```twig
{% if session.usuario_rol in ['admin', 'super_admin'] %}
```

In `src/Controllers/DashboardController.php`, line 26, change:

```php
        $kpis = in_array($usuario['rol'], ['admin', 'super_admin'], true)
            ? $this->service->kpisAdmin()
            : $this->service->kpisEmpleado((int) $usuario['id']);
```

- [ ] **Step 7: Syntax-check the PHP file**

Run: `php -l src/Controllers/DashboardController.php`
Expected: `No syntax errors detected`

- [ ] **Step 8: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: OK, no failures.

- [ ] **Step 9: Manual QA (no automated view tests exist in this project)**

Using the seed data from Task 1, log in as each user (password `password123` for all) and confirm:

- `superadmin` (super_admin): sidebar shows Dashboard/Empleados/Reportes, Operaciones, Nómina, **and** Administración (Puestos/Períodos/Feriados/Usuarios); topbar badge is violet "super_admin"; Dashboard shows KPI cards; `/mantenimientos/usuarios` list shows a "Super Admin" badge for this row.
- `admin` (admin): sidebar shows everything **except** Administración; topbar badge is red "admin"; Dashboard shows KPI cards; visiting `/mantenimientos/puestos` directly redirects to `/dashboard` with the "No tiene permisos..." flash message.
- `jperez` (empleado): sidebar shows the portal/self-service menu only, unchanged from before this feature; Dashboard shows the employee view, not KPI cards.

- [ ] **Step 10: Commit**

```bash
git add templates/layouts/base.html.twig public/assets/css/app.css templates/usuarios/form.html.twig templates/usuarios/index.html.twig templates/dashboard/index.html.twig src/Controllers/DashboardController.php
git commit -m "feat(rbac): reflect super_admin in sidebar, badges, Usuarios form and dashboard KPIs"
```

---

### Task 5: Documentation — `CLAUDE.md`

**Files:**
- Modify: `CLAUDE.md` (section "Autenticación y RBAC")

**Interfaces:** none — documentation only.

- [ ] **Step 1: Update the RBAC section**

In `CLAUDE.md`, in the "## Autenticación y RBAC" section, change the `$_SESSION['usuario_rol']` bullet:

```markdown
  - `$_SESSION['usuario_rol']` — `'super_admin'`, `'admin'` o `'empleado'` (jerárquico: `super_admin` > `admin` > `empleado`)
```

And change the `RoleMiddleware('admin')` bullet to:

```markdown
- `RoleMiddleware($rol)` — jerárquico por rango (`empleado=0, admin=1, super_admin=2`); deja pasar si el rango del usuario es igual o mayor al requerido, y redirige a `/dashboard` si no (leyendo el atributo `usuario`, no la sesión). Solo el grupo `/mantenimientos` (Puestos, Períodos, Feriados, Usuarios) exige `super_admin`; el resto de grupos admin-only siguen exigiendo `admin` (un `super_admin` entra igual, por jerarquía).
```

Right after the RBAC section's bullet list, add this note (same blockquote style already used for the Grupo 7/8 notes):

```markdown
> **Nota sobre roles (13/09/2026, retroalimentación del tutor Braulio Sandí Morales):** se agregó el rol
> `super_admin`, jerárquico por encima de `admin`. Controla exclusivamente Usuarios y los catálogos del
> grupo `/mantenimientos` (Puestos, Períodos, Feriados) — el resto de los módulos siguen aceptando `admin`
> como antes, ya que un `super_admin` los hereda por jerarquía. El sistema no permite que quede activo cero
> `super_admin` a la vez (`UsuariosService::bloquearSiEsUltimoSuperAdminActivo`).
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document the super_admin role hierarchy in CLAUDE.md"
```
