# Autoservicio de Solicitudes (Portal del Empleado) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an employee create, view, edit, and cancel (while pending) their own `solicitudes` (horas_extra/vacaciones/permiso) from the Portal, while the admin keeps exclusive approve/reject power and full visibility — matching Capítulo I of the TFG and the standard approval workflow used by comparable systems.

**Architecture:** Extend the existing `SolicitudesService`/`SolicitudesRepository` (Controller → Service → Repository, no PDO in controllers, no business logic in repositories) with an optional employee-scoping parameter, reused by both the admin controller (unrestricted) and a new set of `PortalController` methods (restricted to the logged-in employee's own `id_empleado`). No schema changes.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, PHP-DI 7, MySQL 8.0 + PDO, PHPUnit 11.

## Global Constraints

- `declare(strict_types=1)` in every file.
- PascalCase classes, camelCase methods, snake_case DB columns/tables.
- Controllers never touch PDO. Services never touch Slim. Repositories hold no business logic.
- Routes: `GET` index, `GET`/`POST crear`, `GET`/`POST {id}/editar`, `POST {id}/eliminar` — no PUT/PATCH/DELETE verbs.
- Admin-only route groups: `->add(new RoleMiddleware('admin'))->add(new AuthMiddleware())`. Portal routes (any authenticated role): `->add(new AuthMiddleware())` only.
- Flash messages via `$_SESSION['flash_success']`/`flash_error'`, consumed once via each controller's private `consumeFlash()`.
- `url_for`/`base_path` in Twig; `RouteContext::fromRequest($request)->getRouteParser()->urlFor()` in controllers. Never hardcode a `Location` path.
- No repository-level or controller-level automated tests exist anywhere in this codebase today — every module's QA gate is manual verification against the running app (see `CLAUDE.md`'s module table: "✅ Completo (pendiente prueba manual)"). This plan follows that same convention: Repository and Controller changes are verified manually in the final task; only the new Service-layer authorization logic gets automated PHPUnit coverage, using `createMock()` against the repository classes (no real PDO needed).

---

## Task 1: `SolicitudesRepository::findAll` — filtro opcional por `id_empleado`

**Files:**
- Modify: `src/Repositories/SolicitudesRepository.php:19-48`

**Interfaces:**
- Produces: `findAll(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array` — new 4th parameter, additive and backward-compatible (existing admin call sites pass 3 args and are unaffected).

- [ ] **Step 1: Modify `findAll` to accept and apply the new filter**

Replace the full method body (lines 19-48) with:

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.tipo, s.fecha_inicio, s.fecha_fin,
                       s.horas, s.motivo, s.estado, s.fecha_solicitud, s.fecha_resolucion,
                       s.observacion_admin, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado";
        $where  = [];
        $params = [];
        if ($tipo !== null) {
            $where[] = 's.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($estado !== null) {
            $where[] = 's.estado = :estado';
            $params[':estado'] = $estado;
        }
        if ($q !== null && $q !== '') {
            $where[] = "(CONCAT(e.nombre, ' ', e.apellidos) LIKE :q OR CONCAT(e.apellidos, ', ', e.nombre) LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        if ($idEmpleado !== null) {
            $where[] = 's.id_empleado = :idEmpleado';
            $params[':idEmpleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.fecha_solicitud DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
```

- [ ] **Step 2: Commit**

```bash
git add src/Repositories/SolicitudesRepository.php
git commit -m "feat(solicitudes): add optional id_empleado filter to findAll"
```

---

## Task 2: `SolicitudesService` — filtro por empleado y control de propiedad

**Files:**
- Modify: `src/Services/SolicitudesService.php:28,58-67,79-90`
- Test: `tests/Services/SolicitudesServiceOwnershipTest.php` (create)

**Interfaces:**
- Consumes: `SolicitudesRepository::findAll(?string, ?string, ?string, ?int)` from Task 1.
- Produces:
  - `listar(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array`
  - `actualizar(int $id, array $datos, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void`
  - `eliminar(int $id, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void`
  - Both throw `RuntimeException('No tiene permiso para modificar esta solicitud.')` when `$ownerIdEmpleado !== null` and it doesn't match the solicitud's real `id_empleado`. This check runs **before** the existing `estado === 'pendiente'` check.

- [ ] **Step 1: Write the failing tests**

Create `tests/Services/SolicitudesServiceOwnershipTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SolicitudesService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SolicitudesServiceOwnershipTest extends TestCase
{
    private function service(SolicitudesRepository $repo, ?EmpleadosRepository $empleadosRepo = null): SolicitudesService
    {
        if ($empleadosRepo === null) {
            $empleadosRepo = $this->createMock(EmpleadosRepository::class);
            $empleadosRepo->method('findById')->willReturn(['id_empleado' => 42, 'estado' => 'activo']);
        }
        return new SolicitudesService(
            $repo,
            $empleadosRepo,
            $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testListarPasaIdEmpleadoAlRepositorio(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())
            ->method('findAll')
            ->with(null, 'pendiente', null, 42)
            ->willReturn([]);

        $this->service($repo)->listar(null, 'pendiente', null, 42);
    }

    public function testActualizarLanzaExcepcionSiElDuenioNoCoincide(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tiene permiso para modificar esta solicitud.');

        $this->service($repo)->actualizar(5, ['tipo' => 'permiso'], 1, '127.0.0.1', 42);
    }

    public function testActualizarPermiteAlDuenioReal(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 42, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(5, [
            'id_empleado'  => 42,
            'tipo'         => 'permiso',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-01',
        ], 1, '127.0.0.1', 42);
    }

    public function testEliminarLanzaExcepcionSiElDuenioNoCoincide(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tiene permiso para modificar esta solicitud.');

        $this->service($repo)->eliminar(5, 1, '127.0.0.1', 42);
    }

    public function testAdminSigueSinRestriccionAlNoEnviarOwnerIdEmpleado(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->method('countDependientes')->willReturn(0);
        $repo->expects(self::once())->method('delete');

        // Sin $ownerIdEmpleado (llamada admin): debe eliminar sin lanzar excepción.
        $this->service($repo)->eliminar(5, 1, '127.0.0.1');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Services/SolicitudesServiceOwnershipTest.php`
Expected: FAIL — `listar()`/`actualizar()`/`eliminar()` do not yet accept a 4th argument (TypeError or ArgumentCountError), and the ownership `RuntimeException` doesn't exist yet.

- [ ] **Step 3: Modify `listar`, `actualizar`, `eliminar`**

In `src/Services/SolicitudesService.php`, replace line 28:

```php
    public function listar(?string $tipo = null, ?string $estado = null, ?string $q = null): array
    {
        return $this->repo->findAll($tipo, $estado, $q);
    }
```

with:

```php
    public function listar(?string $tipo = null, ?string $estado = null, ?string $q = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($tipo, $estado, $q, $idEmpleado);
    }
```

Replace lines 58-67 (`actualizar`):

```php
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('No se puede editar una solicitud ya resuelta.');
        }
        $fila = $this->validar($datos);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }
```

with:

```php
    public function actualizar(int $id, array $datos, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void
    {
        $actual = $this->obtener($id);
        if ($ownerIdEmpleado !== null && (int)$actual['id_empleado'] !== $ownerIdEmpleado) {
            throw new RuntimeException('No tiene permiso para modificar esta solicitud.');
        }
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('No se puede editar una solicitud ya resuelta.');
        }
        $fila = $this->validar($datos);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }
```

Replace lines 79-90 (`eliminar`):

```php
    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') === 'aprobada') {
            throw new RuntimeException('No se puede eliminar una solicitud aprobada.');
        }
        if ($this->repo->countDependientes($id) > 0) {
            throw new RuntimeException('No se puede eliminar: la solicitud tiene registros asociados.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'solicitudes', $id, $ip);
    }
```

with:

```php
    public function eliminar(int $id, int $loggedInId, string $ip, ?int $ownerIdEmpleado = null): void
    {
        $actual = $this->obtener($id);
        if ($ownerIdEmpleado !== null && (int)$actual['id_empleado'] !== $ownerIdEmpleado) {
            throw new RuntimeException('No tiene permiso para modificar esta solicitud.');
        }
        if (($actual['estado'] ?? '') === 'aprobada') {
            throw new RuntimeException('No se puede eliminar una solicitud aprobada.');
        }
        if ($this->repo->countDependientes($id) > 0) {
            throw new RuntimeException('No se puede eliminar: la solicitud tiene registros asociados.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'solicitudes', $id, $ip);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Services/SolicitudesServiceOwnershipTest.php`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add src/Services/SolicitudesService.php tests/Services/SolicitudesServiceOwnershipTest.php
git commit -m "feat(solicitudes): scope listar/actualizar/eliminar to an owning employee"
```

---

## Task 3: `PortalService` — exponer `id_empleado` del usuario autenticado

**Files:**
- Modify: `src/Services/PortalService.php:16` (add method after constructor)
- Test: `tests/Services/PortalServiceIdEmpleadoTest.php` (create)

**Interfaces:**
- Consumes: `PortalRepository::idEmpleadoPorUsuario(int $idUsuario): ?int` (already exists, `src/Repositories/PortalRepository.php:18-26`).
- Produces: `PortalService::idEmpleado(int $idUsuario): ?int`

- [ ] **Step 1: Write the failing test**

Create `tests/Services/PortalServiceIdEmpleadoTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\PortalRepository;
use App\Services\PortalService;
use PHPUnit\Framework\TestCase;

final class PortalServiceIdEmpleadoTest extends TestCase
{
    public function testDevuelveElIdEmpleadoDelRepositorio(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->expects(self::once())
            ->method('idEmpleadoPorUsuario')
            ->with(7)
            ->willReturn(42);

        $service = new PortalService($repo);

        self::assertSame(42, $service->idEmpleado(7));
    }

    public function testDevuelveNullSiNoHayEmpleadoVinculado(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('idEmpleadoPorUsuario')->willReturn(null);

        $service = new PortalService($repo);

        self::assertNull($service->idEmpleado(7));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Services/PortalServiceIdEmpleadoTest.php`
Expected: FAIL — `Call to undefined method App\Services\PortalService::idEmpleado()`

- [ ] **Step 3: Add the method**

In `src/Services/PortalService.php`, immediately after the constructor (after line 16), add:

```php

    public function idEmpleado(int $idUsuario): ?int
    {
        return $this->repo->idEmpleadoPorUsuario($idUsuario);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Services/PortalServiceIdEmpleadoTest.php`
Expected: `OK (2 tests, 2 assertions)`

- [ ] **Step 5: Commit**

```bash
git add src/Services/PortalService.php tests/Services/PortalServiceIdEmpleadoTest.php
git commit -m "feat(portal): expose idEmpleado for the authenticated user"
```

---

## Task 4: `PortalController` — nuevos métodos de autoservicio

**Files:**
- Modify: `src/Controllers/PortalController.php`

**Interfaces:**
- Consumes: `PortalService::idEmpleado(int): ?int` (Task 3); `SolicitudesService::listar/obtener/datosFormulario/crear/actualizar/eliminar` (existing + Task 2).
- Produces: `PortalController::solicitudes`, `crearSolicitud`, `guardarSolicitud`, `editarSolicitud`, `actualizarSolicitud`, `eliminarSolicitud` — all `(Request, Response[, array $args]): Response`.

No automated test for this task (no controller-test precedent in this codebase — Slim requests/sessions aren't mocked anywhere today). Verified manually in Task 8.

- [ ] **Step 1: Add the `SolicitudesService` dependency**

In `src/Controllers/PortalController.php`, update the imports (top of file) and constructor:

```php
use App\Services\PortalService;
use App\Services\SolicitudesService;
```

Replace the constructor:

```php
    public function __construct(
        private readonly Twig          $twig,
        private readonly PortalService $service
    ) {}
```

with:

```php
    public function __construct(
        private readonly Twig               $twig,
        private readonly PortalService       $service,
        private readonly SolicitudesService  $solicitudesService
    ) {}
```

- [ ] **Step 2: Add a private helper to resolve the current employee, redirecting if unlinked**

Add this private method right above `private function consumeFlash` (currently the last method in the class):

```php
    /**
     * Resuelve el id_empleado del usuario autenticado. Si no hay empleado
     * vinculado, deja un flash_error y devuelve null para que el método
     * que llama redirija en vez de continuar.
     */
    private function idEmpleadoOFlash(Request $request): ?int
    {
        $idEmpleado = $this->service->idEmpleado((int) $_SESSION['usuario_id']);
        if ($idEmpleado === null) {
            $_SESSION['flash_error'] = 'Su usuario no está vinculado a un empleado.';
        }
        return $idEmpleado;
    }

    private function redirectToMisSolicitudes(Request $request, Response $response): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.solicitudes');
        return $response->withHeader('Location', $url)->withStatus(302);
    }
```

- [ ] **Step 3: Add `solicitudes` (listado propio)**

Add after the `asistencia` method:

```php

    public function solicitudes(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->twig->render($response, 'portal/mis_solicitudes.html.twig', [
                'titulo'      => 'Mis Solicitudes',
                'vinculado'   => false,
                'solicitudes' => [],
                'flashError'  => $this->consumeFlash('flash_error'),
            ]);
        }

        $estado = ($request->getQueryParams()['estado'] ?? '') !== ''
            ? $request->getQueryParams()['estado']
            : null;

        return $this->twig->render($response, 'portal/mis_solicitudes.html.twig', [
            'titulo'       => 'Mis Solicitudes',
            'vinculado'    => true,
            'solicitudes'  => $this->solicitudesService->listar(null, $estado, null, $idEmpleado),
            'filtroEstado' => $estado,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function crearSolicitud(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        return $this->twig->render($response, 'portal/solicitud_form.html.twig', [
            'titulo'    => 'Nueva Solicitud',
            'accion'    => 'crear',
            'solicitud' => [],
            'errores'   => [],
        ]);
    }

    public function guardarSolicitud(Request $request, Response $response): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $datos = (array) $request->getParsedBody();
        $datos['id_empleado'] = $idEmpleado; // nunca confiar en lo que venga del formulario
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud registrada exitosamente.';
            return $this->redirectToMisSolicitudes($request, $response);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'portal/solicitud_form.html.twig', [
                'titulo'    => 'Nueva Solicitud',
                'accion'    => 'crear',
                'solicitud' => $datos,
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function editarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        try {
            $solicitud = $this->solicitudesService->obtener((int) $args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Solicitud no encontrada.';
            return $this->redirectToMisSolicitudes($request, $response);
        }

        if ((int) $solicitud['id_empleado'] !== $idEmpleado || ($solicitud['estado'] ?? '') !== 'pendiente') {
            $_SESSION['flash_error'] = 'No se puede editar esta solicitud.';
            return $this->redirectToMisSolicitudes($request, $response);
        }

        return $this->twig->render($response, 'portal/solicitud_form.html.twig', [
            'titulo'    => 'Editar Solicitud',
            'accion'    => 'editar',
            'solicitud' => $solicitud,
            'errores'   => [],
        ]);
    }

    public function actualizarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $id         = (int) $args['id'];
        $datos      = (array) $request->getParsedBody();
        $datos['id_empleado'] = $idEmpleado;
        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->actualizar($id, $datos, $loggedInId, $ip, $idEmpleado);
            $_SESSION['flash_success'] = 'Solicitud actualizada correctamente.';
            return $this->redirectToMisSolicitudes($request, $response);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'portal/solicitud_form.html.twig', [
                'titulo'    => 'Editar Solicitud',
                'accion'    => 'editar',
                'solicitud' => array_merge(['id_solicitud' => $id], $datos),
                'errores'   => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirectToMisSolicitudes($request, $response);
        }
    }

    public function eliminarSolicitud(Request $request, Response $response, array $args): Response
    {
        $idEmpleado = $this->idEmpleadoOFlash($request);
        if ($idEmpleado === null) {
            return $this->redirectToMisSolicitudes($request, $response);
        }

        $loggedInId = (int) $_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->solicitudesService->eliminar((int) $args['id'], $loggedInId, $ip, $idEmpleado);
            $_SESSION['flash_success'] = 'Solicitud cancelada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirectToMisSolicitudes($request, $response);
    }
```

- [ ] **Step 4: Add the missing imports used by the new methods**

At the top of `src/Controllers/PortalController.php`, the file already imports `RuntimeException` and `RouteContext`. Add, right below `use RuntimeException;`:

```php
use InvalidArgumentException;
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/PortalController.php
git commit -m "feat(portal): add self-service solicitud create/edit/cancel"
```

---

## Task 5: Rutas — `/portal/solicitudes`

**Files:**
- Modify: `config/routes.php`

**Interfaces:**
- Consumes: `PortalController` methods from Task 4.
- Produces: named routes `portal.solicitudes`, `portal.solicitudes.crear`, `portal.solicitudes.guardar`, `portal.solicitudes.editar`, `portal.solicitudes.actualizar`, `portal.solicitudes.eliminar`.

- [ ] **Step 1: Add the route group**

In `config/routes.php`, insert immediately after the existing `/mi-asistencia` route (after the line ending `->setName('portal.asistencia')->add(new AuthMiddleware());`) and before `/cambiar-contrasena`:

```php
    $app->get('/portal/solicitudes', [\App\Controllers\PortalController::class, 'solicitudes'])
        ->setName('portal.solicitudes')->add(new AuthMiddleware());
    $app->get('/portal/solicitudes/crear', [\App\Controllers\PortalController::class, 'crearSolicitud'])
        ->setName('portal.solicitudes.crear')->add(new AuthMiddleware());
    $app->post('/portal/solicitudes/crear', [\App\Controllers\PortalController::class, 'guardarSolicitud'])
        ->setName('portal.solicitudes.guardar')->add(new AuthMiddleware());
    $app->get('/portal/solicitudes/{id}/editar', [\App\Controllers\PortalController::class, 'editarSolicitud'])
        ->setName('portal.solicitudes.editar')->add(new AuthMiddleware());
    $app->post('/portal/solicitudes/{id}/editar', [\App\Controllers\PortalController::class, 'actualizarSolicitud'])
        ->setName('portal.solicitudes.actualizar')->add(new AuthMiddleware());
    $app->post('/portal/solicitudes/{id}/eliminar', [\App\Controllers\PortalController::class, 'eliminarSolicitud'])
        ->setName('portal.solicitudes.eliminar')->add(new AuthMiddleware());
```

- [ ] **Step 2: Commit**

```bash
git add config/routes.php
git commit -m "feat(portal): register /portal/solicitudes routes"
```

---

## Task 6: Contenedor DI — inyectar `SolicitudesService` en `PortalController`

**Files:**
- Modify: `config/dependencies.php`

**Interfaces:**
- Consumes: `SolicitudesService::class` (already registered).
- Produces: `PortalController` resolved with 3 constructor args instead of 2.

- [ ] **Step 1: Update the `PortalController` factory**

In `config/dependencies.php`, replace:

```php
    \App\Controllers\PortalController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PortalController(
            $c->get(Twig::class),
            $c->get(\App\Services\PortalService::class)
        );
    },
```

with:

```php
    \App\Controllers\PortalController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PortalController(
            $c->get(Twig::class),
            $c->get(\App\Services\PortalService::class),
            $c->get(\App\Services\SolicitudesService::class)
        );
    },
```

- [ ] **Step 2: Commit**

```bash
git add config/dependencies.php
git commit -m "feat(portal): wire SolicitudesService into PortalController"
```

---

## Task 7: Plantillas Twig — Mis Solicitudes + formulario + navbar

**Files:**
- Create: `templates/portal/mis_solicitudes.html.twig`
- Create: `templates/portal/solicitud_form.html.twig`
- Modify: `templates/layouts/base.html.twig:86` (add nav link after this line)

**Interfaces:**
- Consumes: variables produced by `PortalController::solicitudes`/`crearSolicitud`/`editarSolicitud` (Task 4): `vinculado` (bool), `solicitudes` (array), `filtroEstado` (?string), `solicitud` (array), `accion` ('crear'|'editar'), `errores` (array), `flashSuccess`/`flashError` (?string).

- [ ] **Step 1: Create `templates/portal/mis_solicitudes.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Mis Solicitudes{% endblock %}
{% block breadcrumb %}Inicio / Mis Solicitudes{% endblock %}

{% block contenido %}
{% if not vinculado %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
    Su usuario no está vinculado a un empleado.
</div>
{% else %}

<div class="d-flex justify-content-between align-items-center mb-3" style="max-width:900px">
    <h6 class="fw-bold mb-0"><i class="bi bi-inbox me-1"></i>Mis Solicitudes</h6>
    <a href="{{ url_for('portal.solicitudes.crear') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Nueva solicitud
    </a>
</div>

{% if flashSuccess %}<div class="alert alert-success" style="max-width:900px">{{ flashSuccess }}</div>{% endif %}
{% if flashError %}<div class="alert alert-danger" style="max-width:900px">{{ flashError }}</div>{% endif %}

<div class="card-fo" style="max-width:900px">
    <table class="table table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>Tipo</th><th>Inicio</th><th>Fin</th>
                <th class="text-end">Horas</th><th>Motivo</th><th>Estado</th><th class="text-center">Acciones</th>
            </tr>
        </thead>
        <tbody>
            {% for s in solicitudes %}
            <tr>
                <td>
                    {% if s.tipo == 'horas_extra' %}Horas extra
                    {% elseif s.tipo == 'vacaciones' %}Vacaciones
                    {% else %}Permiso{% endif %}
                </td>
                <td>{{ s.fecha_inicio|date('d/m/Y') }}</td>
                <td>{{ s.fecha_fin ? s.fecha_fin|date('d/m/Y') : '—' }}</td>
                <td class="text-end">{{ s.horas ?? '—' }}</td>
                <td>{{ s.motivo ?? '—' }}</td>
                <td>
                    {% if s.estado == 'pendiente' %}<span class="badge bg-warning text-dark">Pendiente</span>
                    {% elseif s.estado == 'aprobada' %}<span class="badge bg-success">Aprobada</span>
                    {% else %}<span class="badge bg-danger">Rechazada</span>{% endif %}
                </td>
                <td class="text-center text-nowrap">
                    {% if s.estado == 'pendiente' %}
                    <a href="{{ url_for('portal.solicitudes.editar', {'id': s.id_solicitud}) }}" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil-square"></i></a>
                    <button type="button" class="btn btn-sm btn-outline-danger" title="Cancelar"
                            data-bs-toggle="modal" data-bs-target="#modalCancelar"
                            data-url="{{ url_for('portal.solicitudes.eliminar', {'id': s.id_solicitud}) }}"><i class="bi bi-x-lg"></i></button>
                    {% endif %}
                </td>
            </tr>
            {% else %}
            <tr><td colspan="7" class="text-center text-muted">No tiene solicitudes registradas.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="modal fade" id="modalCancelar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Cancelar solicitud</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Cancelar esta solicitud pendiente?</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                <form id="formCancelar" method="POST" action="">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Cancelar solicitud</button>
                </form>
            </div>
        </div>
    </div>
</div>

{% endif %}
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalCancelar')?.addEventListener('show.bs.modal', function (event) {
    document.getElementById('formCancelar').action = event.relatedTarget.getAttribute('data-url');
});
</script>
{% endblock %}
```

- [ ] **Step 2: Create `templates/portal/solicitud_form.html.twig`**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}
{% block breadcrumb %}Inicio / Mis Solicitudes / {{ accion == 'crear' ? 'Nueva' : 'Editar' }}{% endblock %}

{% block contenido %}
<div class="card-fo" style="max-width:600px">
    <h6 class="fw-bold mb-3"><i class="bi bi-inbox me-1"></i>{{ titulo }}</h6>

    {% if errores|length > 0 %}
    <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
    {% endif %}

    {% if accion == 'crear' %}
        {% set form_action = url_for('portal.solicitudes.guardar') %}
    {% else %}
        {% set form_action = url_for('portal.solicitudes.actualizar', {'id': solicitud.id_solicitud}) %}
    {% endif %}

    <form method="POST" action="{{ form_action }}" novalidate>

        <div class="mb-3">
            <label for="tipo" class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
            <select class="form-select" id="tipo" name="tipo" required>
                <option value="">— Seleccione —</option>
                <option value="horas_extra" {{ (solicitud.tipo ?? '') == 'horas_extra' ? 'selected' : '' }}>Horas extra</option>
                <option value="vacaciones"  {{ (solicitud.tipo ?? '') == 'vacaciones'  ? 'selected' : '' }}>Vacaciones</option>
                <option value="permiso"     {{ (solicitud.tipo ?? '') == 'permiso'     ? 'selected' : '' }}>Permiso</option>
            </select>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label for="fecha_inicio" class="form-label fw-semibold">Fecha de inicio <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required value="{{ solicitud.fecha_inicio ?? '' }}">
            </div>
            <div class="col-md-6">
                <label for="fecha_fin" class="form-label fw-semibold">Fecha de fin</label>
                <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="{{ solicitud.fecha_fin ?? '' }}">
                <div class="form-text">Requerida para vacaciones y permisos.</div>
            </div>
        </div>

        <div class="mb-3 mt-3">
            <label for="horas" class="form-label fw-semibold">Horas</label>
            <input type="number" step="0.01" min="0" max="999.99" class="form-control" id="horas" name="horas" value="{{ solicitud.horas ?? '' }}">
            <div class="form-text">Solo para solicitudes de horas extra.</div>
        </div>

        <div class="mb-3">
            <label for="motivo" class="form-label fw-semibold">Motivo</label>
            <textarea class="form-control" id="motivo" name="motivo" maxlength="255" rows="2">{{ solicitud.motivo ?? '' }}</textarea>
        </div>

        <div class="d-flex justify-content-between mt-4">
            <a href="{{ url_for('portal.solicitudes') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Enviar' : 'Actualizar' }}</button>
        </div>

    </form>
</div>
{% endblock %}
```

- [ ] **Step 3: Add the navbar link**

In `templates/layouts/base.html.twig`, the "Mi cuenta" sidebar group currently reads (around line 82-89):

```twig
            <a class="sb-item" href="{{ url_for('portal.asistencia') }}"><i class="bi bi-clock-history"></i><span>Mi Asistencia</span></a>
            <a class="sb-item" href="{{ url_for('evaluaciones.mis') }}"><i class="bi bi-star-half"></i><span>Mis Evaluaciones</span></a>
```

Insert a new line between them:

```twig
            <a class="sb-item" href="{{ url_for('portal.asistencia') }}"><i class="bi bi-clock-history"></i><span>Mi Asistencia</span></a>
            <a class="sb-item" href="{{ url_for('portal.solicitudes') }}"><i class="bi bi-inbox"></i><span>Mis Solicitudes</span></a>
            <a class="sb-item" href="{{ url_for('evaluaciones.mis') }}"><i class="bi bi-star-half"></i><span>Mis Evaluaciones</span></a>
```

- [ ] **Step 4: Commit**

```bash
git add templates/portal/mis_solicitudes.html.twig templates/portal/solicitud_form.html.twig templates/layouts/base.html.twig
git commit -m "feat(portal): add Mis Solicitudes templates and nav link"
```

---

## Task 8: Ejecutar la suite de pruebas automatizadas completa

**Files:** none (verification only)

- [ ] **Step 1: Run the full PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: all tests pass, including the 7 new ones added in Tasks 2-3, with zero failures/errors. Example expected tail:

```
OK (NN tests, MM assertions)
```

(NN/MM will include every pre-existing test plus the 7 new ones; if any pre-existing test now fails, stop and fix before continuing — it means Task 2's signature changes broke an existing call site that wasn't covered by this plan.)

- [ ] **Step 2: Search for any other caller of the changed signatures**

Run: `grep -rn "->listar(\|->actualizar(\|->eliminar(" src/Controllers/SolicitudesController.php`
Expected: `index` calls `listar($tipo, $estado, $q)` (3 args — still valid, 4th param defaults to `null`); `update` calls `actualizar($id, $datos, $loggedInId, $ip)` (4 args — still valid, 5th param defaults to `null`); `destroy` calls `eliminar($id, $loggedInId, $ip)` (3 args — still valid). No changes needed to `SolicitudesController` — confirm this by reading the grep output, not just assuming it.

---

## Task 9: Verificación manual end-to-end

**Files:** none (manual QA, matches this project's established convention)

- [ ] **Step 1: Start the app**

Run: `composer start` (or `php -S localhost:8080 -t public public/index.php`), then open `http://localhost:8080/login`.

- [ ] **Step 2: Verify self-service as an employee**

1. Log in with an `empleado`-role user linked to an active employee record.
2. Click "Mis Solicitudes" in the sidebar → confirm it loads with an empty or existing list, no error.
3. Click "Nueva solicitud" → fill Tipo=`Vacaciones`, Fecha inicio and Fecha fin (future dates), submit.
4. Expected: redirected to "Mis Solicitudes" with a green flash "Solicitud registrada exitosamente." and the new row shows `Estado = Pendiente`.
5. Click "Editar" on that row → change the motivo → submit. Expected: updated row reflects the new motivo.
6. Click "Cancelar" on that row, confirm in the modal. Expected: row disappears from the list (deleted).

- [ ] **Step 3: Verify the admin side is unaffected and sees the employee's request**

1. Create a second solicitud as the employee (repeat step 2.3), leave it pending.
2. Log out, log in as `admin`.
3. Go to `/solicitudes` → confirm the employee-created solicitud appears in the list with the correct employee name and `Pendiente` status.
4. Approve it. Expected: same behavior as any admin-created solicitud (estado → Aprobada, no error).

- [ ] **Step 4: Verify an employee cannot touch another employee's solicitud**

1. As the employee from Step 2, note the `id_solicitud` of a *different* employee's pending request (check via admin's `/solicitudes` page or DB).
2. While logged in as the first employee, manually visit `http://localhost:8080/portal/solicitudes/{that-other-id}/editar`.
3. Expected: redirected back to "Mis Solicitudes" with a red flash "No se puede editar esta solicitud." — never shows the other employee's data in a form.

- [ ] **Step 5: Verify an unlinked user doesn't 500**

1. If a test user with role `empleado` exists but has no row in `empleados` linked via `id_usuario` (or temporarily unlink one in the DB for this check), log in as that user and visit `/portal/solicitudes`.
2. Expected: page renders with "Su usuario no está vinculado a un empleado." — no 500 error.

- [ ] **Step 6: Final commit**

If any issue surfaced and was fixed during manual verification, commit it now with a specific message describing the fix. If nothing needed fixing, this task produces no commit — the feature is done.
