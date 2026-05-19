# Auth + Mantenimientos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stub login with real bcrypt authentication and implement full CRUD for Usuarios, Períodos de Pago, and Feriados — all with audit logging.

**Architecture:** Shared `UsuariosRepository` injected into both `AuthService` and `UsuariosService`. A single append-only `AuditoriaRepository` injected into every service that writes audit records. Controllers are the only layer that reads `$_SERVER` and `$_SESSION`.

**Tech Stack:** PHP 8.1, Slim 4, Twig 3, PHP-DI 7, PDO/MySQL, Bootstrap 5, Bootstrap Icons.

**Spec:** `docs/superpowers/specs/2026-05-19-auth-mantenimientos-design.md`

---

## Task 1: Fix seed.sql with real bcrypt hashes

**Files:**
- Modify: `database/seed.sql`

- [ ] **Step 1: Replace placeholder hashes**

Open `database/seed.sql` and replace the two fake hashes on lines 12–13 with this content:

```sql
INSERT INTO `usuarios` (`nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
('admin',  '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'admin',    1),
('jperez', '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'empleado', 1);
```

Both users log in with password `password123` (bcrypt cost 12).

- [ ] **Step 2: Import seed into MySQL**

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < database/seed.sql
```

Expected output: no errors. If you see duplicate-key errors, the seed was already partially imported — run `TRUNCATE` first or re-import the full schema.

- [ ] **Step 3: Verify data**

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root -e "SELECT id_usuario, nombre_usuario, rol, activo FROM lubrimotos_nomina.usuarios;"
```

Expected:
```
id_usuario  nombre_usuario  rol       activo
1           admin           admin     1
2           jperez          empleado  1
```

- [ ] **Step 4: Commit**

```bash
git add database/seed.sql
git commit -m "fix: replace placeholder bcrypt hashes in seed.sql with real hashes"
```

---

## Task 2: AuditoriaRepository

**Files:**
- Create: `src/Repositories/AuditoriaRepository.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Psr\Log\LoggerInterface;

class AuditoriaRepository
{
    public function __construct(
        private readonly PDO             $pdo,
        private readonly LoggerInterface $logger
    ) {}

    public function insert(
        string  $accion,
        int     $idUsuario,
        string  $tablaAfectada,
        ?int    $idRegistro,
        string  $ipOrigen,
        ?string $detalle = null
    ): void {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO auditoria
                     (id_usuario, tabla_afectada, accion, id_registro, ip_origen, detalle)
                 VALUES
                     (:id_usuario, :tabla_afectada, :accion, :id_registro, :ip_origen, :detalle)'
            );
            $stmt->execute([
                ':id_usuario'     => $idUsuario,
                ':tabla_afectada' => $tablaAfectada,
                ':accion'         => $accion,
                ':id_registro'    => $idRegistro,
                ':ip_origen'      => $ipOrigen,
                ':detalle'        => $detalle,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Auditoria insert failed: ' . $e->getMessage(), [
                'accion'    => $accion,
                'idUsuario' => $idUsuario,
            ]);
        }
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Repositories/AuditoriaRepository.php
```

Expected: `No syntax errors detected in src/Repositories/AuditoriaRepository.php`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/AuditoriaRepository.php
git commit -m "feat: add AuditoriaRepository (append-only, failures swallowed)"
```

---

## Task 3: UsuariosRepository

**Files:**
- Create: `src/Repositories/UsuariosRepository.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UsuariosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByNombreUsuario(string $nombreUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, nombre_usuario, contrasena_hash, rol, activo
             FROM usuarios WHERE nombre_usuario = :nombre LIMIT 1'
        );
        $stmt->execute([':nombre' => $nombreUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_usuario, nombre_usuario, rol, activo, fecha_creacion
             FROM usuarios ORDER BY nombre_usuario'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario, nombre_usuario, rol, activo, fecha_creacion
             FROM usuarios WHERE id_usuario = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insert(string $nombreUsuario, string $hash, string $rol): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nombre_usuario, contrasena_hash, rol)
             VALUES (:nombre, :hash, :rol)'
        );
        $stmt->execute([':nombre' => $nombreUsuario, ':hash' => $hash, ':rol' => $rol]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $nombreUsuario, string $rol, int $activo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios
             SET nombre_usuario = :nombre, rol = :rol, activo = :activo
             WHERE id_usuario = :id'
        );
        $stmt->execute([
            ':nombre' => $nombreUsuario,
            ':rol'    => $rol,
            ':activo' => $activo,
            ':id'     => $id,
        ]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET contrasena_hash = :hash WHERE id_usuario = :id'
        );
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuarios WHERE id_usuario = :id');
        $stmt->execute([':id' => $id]);
    }

    public function hasLinkedEmpleado(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM empleados WHERE id_usuario = :id'
        );
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Repositories/UsuariosRepository.php
```

Expected: `No syntax errors detected in src/Repositories/UsuariosRepository.php`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/UsuariosRepository.php
git commit -m "feat: add UsuariosRepository (shared by AuthService and UsuariosService)"
```

---

## Task 4: AuthService

**Files:**
- Create: `src/Services/AuthService.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use InvalidArgumentException;

class AuthService
{
    public function __construct(
        private readonly UsuariosRepository  $usuariosRepo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function verificarCredenciales(string $usuario, string $password, string $ip): array
    {
        $row = $this->usuariosRepo->findByNombreUsuario($usuario);

        if ($row === null || !(bool)$row['activo']) {
            throw new InvalidArgumentException('Credenciales incorrectas.');
        }

        if (!password_verify($password, $row['contrasena_hash'])) {
            throw new InvalidArgumentException('Credenciales incorrectas.');
        }

        $this->auditoriaRepo->insert(
            'LOGIN',
            (int)$row['id_usuario'],
            'usuarios',
            (int)$row['id_usuario'],
            $ip
        );

        return [
            'id_usuario'     => (int)$row['id_usuario'],
            'nombre_usuario' => $row['nombre_usuario'],
            'rol'            => $row['rol'],
        ];
    }

    public function cerrarSesion(int $userId, string $ip): void
    {
        $this->auditoriaRepo->insert('LOGOUT', $userId, 'usuarios', $userId, $ip);
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Services/AuthService.php
```

Expected: `No syntax errors detected in src/Services/AuthService.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/AuthService.php
git commit -m "feat: add AuthService with bcrypt verification and audit logging"
```

---

## Task 5: Replace AuthController

**Files:**
- Modify: `src/Controllers/AuthController.php`

- [ ] **Step 1: Replace the entire file**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private readonly Twig        $twig,
        private readonly AuthService $authService
    ) {}

    public function showLogin(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['usuario_id'])) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        $flashError = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'auth/login.html.twig', [
            'titulo'     => 'Iniciar Sesión',
            'flashError' => $flashError,
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = (array)$request->getParsedBody();
        $usuario  = trim($body['nombre_usuario'] ?? '');
        $password = trim($body['contrasena'] ?? '');
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $data = $this->authService->verificarCredenciales($usuario, $password, $ip);
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $_SESSION['usuario_id']     = $data['id_usuario'];
        $_SESSION['usuario_nombre'] = $data['nombre_usuario'];
        $_SESSION['usuario_rol']    = $data['rol'];

        return $response->withHeader('Location', '/dashboard')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $userId = (int)($_SESSION['usuario_id'] ?? 0);
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($userId > 0) {
            $this->authService->cerrarSesion($userId, $ip);
        }

        session_unset();
        session_destroy();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Controllers/AuthController.php
```

Expected: `No syntax errors detected in src/Controllers/AuthController.php`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/AuthController.php
git commit -m "feat: replace AuthController stub with real bcrypt login and audit logout"
```

---

## Task 6: UsuariosService

**Files:**
- Create: `src/Services/UsuariosService.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\UsuariosRepository;
use InvalidArgumentException;
use RuntimeException;

class UsuariosService
{
    private const ROLES_VALIDOS = ['admin', 'empleado'];

    public function __construct(
        private readonly UsuariosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function listar(): array
    {
        return $this->repo->findAll();
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        return $row;
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        $nombre   = trim($datos['nombre_usuario'] ?? '');
        $rol      = trim($datos['rol'] ?? '');
        $password = $datos['contrasena'] ?? '';

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre de usuario es obligatorio.');
        }
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException('El rol seleccionado no es válido.');
        }
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $newId = $this->repo->insert($nombre, $hash, $rol);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('El nombre de usuario ya existe.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'usuarios', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current = $this->obtener($id);

        $nombre = trim($datos['nombre_usuario'] ?? '');
        $rol    = trim($datos['rol'] ?? '');
        $activo = (int)($datos['activo'] ?? 0);

        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre de usuario es obligatorio.');
        }
        if (!in_array($rol, self::ROLES_VALIDOS, true)) {
            throw new InvalidArgumentException('El rol seleccionado no es válido.');
        }
        if ($id === $loggedInId) {
            if ($rol !== $current['rol'] || $activo !== (int)$current['activo']) {
                throw new InvalidArgumentException('No puedes modificar tu propio rol o estado.');
            }
        }

        try {
            $this->repo->update($id, $nombre, $rol, $activo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('El nombre de usuario ya existe.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'usuarios', $id, $ip);
    }

    public function resetearPassword(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);

        $password = $datos['contrasena'] ?? '';
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->repo->updatePassword($id, $hash);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'usuarios', $id, $ip, 'password_reset');
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);

        if ($id === $loggedInId) {
            throw new InvalidArgumentException('No puedes eliminar tu propia cuenta.');
        }
        if ($this->repo->hasLinkedEmpleado($id)) {
            throw new InvalidArgumentException('No se puede eliminar: el usuario tiene un empleado asociado.');
        }

        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'usuarios', $id, $ip);
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Services/UsuariosService.php
```

Expected: `No syntax errors detected in src/Services/UsuariosService.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/UsuariosService.php
git commit -m "feat: add UsuariosService with full CRUD, self-lock guard, and audit logging"
```

---

## Task 7: UsuariosController + templates

**Files:**
- Create: `src/Controllers/UsuariosController.php`
- Create: `templates/usuarios/index.html.twig`
- Create: `templates/usuarios/form.html.twig`
- Create: `templates/usuarios/password.html.twig`

- [ ] **Step 1: Create UsuariosController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UsuariosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

class UsuariosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly UsuariosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'usuarios/index.html.twig', [
            'titulo'       => 'Usuarios del Sistema',
            'usuarios'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'usuarios/form.html.twig', [
            'titulo'  => 'Crear Usuario',
            'accion'  => 'crear',
            'usuario' => [],
            'errores' => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario creado exitosamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            unset($datos['contrasena']);
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', [
                'titulo'  => 'Crear Usuario',
                'accion'  => 'crear',
                'usuario' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $usuario = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Usuario no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }

        return $this->twig->render($response, 'usuarios/form.html.twig', [
            'titulo'  => 'Editar Usuario',
            'accion'  => 'editar',
            'usuario' => $usuario,
            'errores' => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario actualizado correctamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', [
                'titulo'  => 'Editar Usuario',
                'accion'  => 'editar',
                'usuario' => array_merge(['id_usuario' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Usuario eliminado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
    }

    public function showPasswordReset(Request $request, Response $response, array $args): Response
    {
        try {
            $usuario = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Usuario no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }

        return $this->twig->render($response, 'usuarios/password.html.twig', [
            'titulo'  => 'Restablecer Contraseña',
            'usuario' => $usuario,
            'errores' => [],
        ]);
    }

    public function updatePassword(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->resetearPassword($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Contraseña restablecida correctamente.';
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $usuario = $this->service->obtener($id);
            return $this->twig->render($response->withStatus(422), 'usuarios/password.html.twig', [
                'titulo'  => 'Restablecer Contraseña',
                'usuario' => $usuario,
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/usuarios')->withStatus(302);
        }
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Controllers/UsuariosController.php
```

Expected: `No syntax errors detected in src/Controllers/UsuariosController.php`

- [ ] **Step 3: Create templates/usuarios/index.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Usuarios del Sistema{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item active">Usuarios</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-person-gear me-2 text-primary"></i>Usuarios del Sistema
    </h2>
    <a href="{{ path_for('usuarios.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nuevo Usuario
    </a>
</div>

{% if usuarios|length == 0 %}
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    No hay usuarios registrados.
</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Nombre de Usuario</th>
                    <th>Rol</th>
                    <th>Estado</th>
                    <th>Creado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for u in usuarios %}
                <tr>
                    <td class="text-muted small">{{ u.id_usuario }}</td>
                    <td class="fw-semibold">{{ u.nombre_usuario }}</td>
                    <td>
                        {% if u.rol == 'admin' %}
                            <span class="badge bg-danger">Admin</span>
                        {% else %}
                            <span class="badge bg-secondary">Empleado</span>
                        {% endif %}
                    </td>
                    <td>
                        {% if u.activo %}
                            <span class="badge bg-success">Activo</span>
                        {% else %}
                            <span class="badge bg-warning text-dark">Inactivo</span>
                        {% endif %}
                    </td>
                    <td class="text-muted small">{{ u.fecha_creacion|date('d/m/Y') }}</td>
                    <td class="text-center">
                        <a href="{{ path_for('usuarios.edit', {'id': u.id_usuario}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                        <a href="{{ path_for('usuarios.password', {'id': u.id_usuario}) }}"
                           class="btn btn-sm btn-outline-warning me-1" title="Restablecer contraseña">
                            <i class="bi bi-key"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Eliminar"
                                data-bs-toggle="modal"
                                data-bs-target="#modalEliminar"
                                data-id="{{ u.id_usuario }}"
                                data-nombre="{{ u.nombre_usuario }}">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Total: {{ usuarios|length }} usuario(s).
    </div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el usuario <strong id="modalNombreUsuario"></strong>?
                Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn    = event.relatedTarget;
    const id     = btn.getAttribute('data-id');
    const nombre = btn.getAttribute('data-nombre');
    document.getElementById('modalNombreUsuario').textContent = '"' + nombre + '"';
    document.getElementById('formEliminar').action = '/mantenimientos/usuarios/' + id + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 4: Create templates/usuarios/form.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item"><a href="{{ path_for('usuarios.index') }}">Usuarios</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nuevo' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-person-gear me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        {% for error in errores %}<li>{{ error }}</li>{% endfor %}
                    </ul>
                </div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = path_for('usuarios.store') %}
                {% else %}
                    {% set form_action = path_for('usuarios.update', {'id': usuario.id_usuario}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="nombre_usuario" class="form-label fw-semibold">
                            Nombre de usuario <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="nombre_usuario"
                               name="nombre_usuario" maxlength="80" required
                               value="{{ usuario.nombre_usuario ?? '' }}">
                    </div>

                    <div class="mb-3">
                        <label for="rol" class="form-label fw-semibold">
                            Rol <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="rol" name="rol" required>
                            <option value="">— Seleccione —</option>
                            <option value="admin"
                                {{ (usuario.rol ?? '') == 'admin' ? 'selected' : '' }}>
                                Administrador
                            </option>
                            <option value="empleado"
                                {{ (usuario.rol ?? '') == 'empleado' ? 'selected' : '' }}>
                                Empleado
                            </option>
                        </select>
                    </div>

                    {% if accion == 'crear' %}
                    <div class="mb-3">
                        <label for="contrasena" class="form-label fw-semibold">
                            Contraseña <span class="text-danger">*</span>
                        </label>
                        <input type="password" class="form-control" id="contrasena"
                               name="contrasena" minlength="8" required
                               placeholder="Mínimo 8 caracteres">
                    </div>
                    {% endif %}

                    {% if accion == 'editar' %}
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="activo"
                               name="activo" value="1"
                               {{ usuario.activo ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="activo">
                            Usuario activo
                        </label>
                    </div>
                    {% endif %}

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ path_for('usuarios.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>
                            {{ accion == 'crear' ? 'Crear Usuario' : 'Actualizar Usuario' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Create templates/usuarios/password.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item"><a href="{{ path_for('usuarios.index') }}">Usuarios</a></li>
        <li class="breadcrumb-item active">Restablecer contraseña</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-key me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                <p class="text-muted mb-3">
                    Establecer nueva contraseña para
                    <strong>{{ usuario.nombre_usuario }}</strong>.
                </p>

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        {% for error in errores %}<li>{{ error }}</li>{% endfor %}
                    </ul>
                </div>
                {% endif %}

                <form method="POST"
                      action="{{ path_for('usuarios.updatePassword', {'id': usuario.id_usuario}) }}"
                      novalidate>

                    <div class="mb-3">
                        <label for="contrasena" class="form-label fw-semibold">
                            Nueva contraseña <span class="text-danger">*</span>
                        </label>
                        <input type="password" class="form-control" id="contrasena"
                               name="contrasena" minlength="8" required
                               placeholder="Mínimo 8 caracteres">
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ path_for('usuarios.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-floppy me-1"></i>Guardar contraseña
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/UsuariosController.php templates/usuarios/
git commit -m "feat: add UsuariosController and Twig templates (index, form, password)"
```

---

## Task 8: PeriodosRepository

**Files:**
- Create: `src/Repositories/PeriodosRepository.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PeriodosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
             FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
             FROM periodos_pago WHERE id_periodo = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function findLatest(): ?array
    {
        $row = $this->pdo->query(
            'SELECT fecha_inicio, fecha_fin FROM periodos_pago ORDER BY fecha_inicio DESC LIMIT 1'
        )->fetch();
        return $row !== false ? $row : null;
    }

    public function findOverlapping(string $fechaInicio, string $fechaFin, ?int $excludeId = null): array
    {
        $sql    = 'SELECT id_periodo FROM periodos_pago
                   WHERE fecha_inicio <= :fin AND fecha_fin >= :inicio';
        $params = [':inicio' => $fechaInicio, ':fin' => $fechaFin];

        if ($excludeId !== null) {
            $sql .= ' AND id_periodo != :excludeId';
            $params[':excludeId'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert(string $fechaInicio, string $fechaFin): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO periodos_pago (fecha_inicio, fecha_fin, estado)
             VALUES (:inicio, :fin, :estado)'
        );
        $stmt->execute([':inicio' => $fechaInicio, ':fin' => $fechaFin, ':estado' => 'abierto']);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $fechaInicio, string $fechaFin, string $estado): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE periodos_pago
             SET fecha_inicio = :inicio, fecha_fin = :fin, estado = :estado
             WHERE id_periodo = :id'
        );
        $stmt->execute([
            ':inicio' => $fechaInicio,
            ':fin'    => $fechaFin,
            ':estado' => $estado,
            ':id'     => $id,
        ]);
    }

    public function hasLinkedRecords(int $id): bool
    {
        foreach (['asistencia', 'horas_extra', 'vacaciones', 'incapacidades', 'permisos'] as $table) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id_periodo = :id");
            $stmt->execute([':id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        return false;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM periodos_pago WHERE id_periodo = :id');
        $stmt->execute([':id' => $id]);
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Repositories/PeriodosRepository.php
```

Expected: `No syntax errors detected in src/Repositories/PeriodosRepository.php`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/PeriodosRepository.php
git commit -m "feat: add PeriodosRepository with overlap detection and linked-records check"
```

---

## Task 9: PeriodosService

**Files:**
- Create: `src/Services/PeriodosService.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class PeriodosService
{
    public function __construct(
        private readonly PeriodosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function listar(): array
    {
        return $this->repo->findAll();
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Período no encontrado.');
        }
        return $row;
    }

    public function sugerirSiguiente(): array
    {
        $latest = $this->repo->findLatest();
        if ($latest === null) {
            return ['fecha_inicio' => date('Y-m-01'), 'fecha_fin' => date('Y-m-15')];
        }

        $next = (new DateTimeImmutable($latest['fecha_fin']))->modify('+1 day');
        $day  = (int)$next->format('j');

        if ($day === 1) {
            return ['fecha_inicio' => $next->format('Y-m-d'), 'fecha_fin' => $next->format('Y-m-15')];
        }

        return ['fecha_inicio' => $next->format('Y-m-d'), 'fecha_fin' => $next->format('Y-m-t')];
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        ['fecha_inicio' => $inicio, 'fecha_fin' => $fin] = $this->validarRangoQuincenal($datos);
        $this->validarSinSolapamiento($inicio, $fin);

        $newId = $this->repo->insert($inicio, $fin);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'periodos_pago', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $current     = $this->obtener($id);
        $nuevoEstado = trim($datos['estado'] ?? $current['estado']);

        if ($current['estado'] === 'cerrado' && $nuevoEstado === 'abierto') {
            throw new InvalidArgumentException('Un período cerrado no puede reabrirse.');
        }
        if (!in_array($nuevoEstado, ['abierto', 'cerrado'], true)) {
            throw new InvalidArgumentException('Estado no válido.');
        }

        ['fecha_inicio' => $inicio, 'fecha_fin' => $fin] = $this->validarRangoQuincenal($datos);
        $this->validarSinSolapamiento($inicio, $fin, $id);

        $this->repo->update($id, $inicio, $fin, $nuevoEstado);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'periodos_pago', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $current = $this->obtener($id);

        if ($current['estado'] === 'cerrado') {
            throw new InvalidArgumentException('No se puede eliminar un período cerrado.');
        }
        if ($this->repo->hasLinkedRecords($id)) {
            throw new InvalidArgumentException('No se puede eliminar: el período tiene registros asociados.');
        }

        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'periodos_pago', $id, $ip);
    }

    private function validarRangoQuincenal(array $datos): array
    {
        $inicio = trim($datos['fecha_inicio'] ?? '');
        $fin    = trim($datos['fecha_fin'] ?? '');

        if ($inicio === '' || $fin === '') {
            throw new InvalidArgumentException('Las fechas de inicio y fin son obligatorias.');
        }

        $dtInicio = DateTimeImmutable::createFromFormat('Y-m-d', $inicio);
        $dtFin    = DateTimeImmutable::createFromFormat('Y-m-d', $fin);

        if ($dtInicio === false || $dtFin === false) {
            throw new InvalidArgumentException('Formato de fecha inválido. Use YYYY-MM-DD.');
        }

        $dayInicio = (int)$dtInicio->format('j');
        $dayFin    = (int)$dtFin->format('j');
        $lastDay   = (int)$dtFin->format('t');
        $mismoMes  = $dtInicio->format('Y-m') === $dtFin->format('Y-m');

        $valido = $mismoMes && (
            ($dayInicio === 1  && $dayFin === 15)      ||
            ($dayInicio === 16 && $dayFin === $lastDay)
        );

        if (!$valido) {
            throw new InvalidArgumentException(
                'El período debe ser del 1 al 15 o del 16 al último día del mes.'
            );
        }

        return ['fecha_inicio' => $inicio, 'fecha_fin' => $fin];
    }

    private function validarSinSolapamiento(string $inicio, string $fin, ?int $excludeId = null): void
    {
        if ($this->repo->findOverlapping($inicio, $fin, $excludeId) !== []) {
            throw new InvalidArgumentException('Ya existe un período que se cruza con esas fechas.');
        }
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Services/PeriodosService.php
```

Expected: `No syntax errors detected in src/Services/PeriodosService.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/PeriodosService.php
git commit -m "feat: add PeriodosService with quincenal validation and estado transition guard"
```

---

## Task 10: PeriodosController + templates

**Files:**
- Create: `src/Controllers/PeriodosController.php`
- Create: `templates/periodos/index.html.twig`
- Create: `templates/periodos/form.html.twig`

- [ ] **Step 1: Create PeriodosController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PeriodosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

class PeriodosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly PeriodosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'periodos/index.html.twig', [
            'titulo'       => 'Períodos de Pago',
            'periodos'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'periodos/form.html.twig', [
            'titulo'   => 'Nuevo Período de Pago',
            'accion'   => 'crear',
            'periodo'  => $this->service->sugerirSiguiente(),
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período creado exitosamente.';
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'periodos/form.html.twig', [
                'titulo'  => 'Nuevo Período de Pago',
                'accion'  => 'crear',
                'periodo' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $periodo = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Período no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        }

        return $this->twig->render($response, 'periodos/form.html.twig', [
            'titulo'  => 'Editar Período de Pago',
            'accion'  => 'editar',
            'periodo' => $periodo,
            'errores' => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período actualizado correctamente.';
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'periodos/form.html.twig', [
                'titulo'  => 'Editar Período de Pago',
                'accion'  => 'editar',
                'periodo' => array_merge(['id_periodo' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Período eliminado correctamente.';
        } catch (InvalidArgumentException|RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/mantenimientos/periodos')->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Controllers/PeriodosController.php
```

Expected: `No syntax errors detected in src/Controllers/PeriodosController.php`

- [ ] **Step 3: Create templates/periodos/index.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Períodos de Pago{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item active">Períodos de Pago</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-calendar-range me-2 text-primary"></i>Períodos de Pago
    </h2>
    <a href="{{ path_for('periodos.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nuevo Período
    </a>
</div>

{% if periodos|length == 0 %}
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No hay períodos registrados.
</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Fecha Inicio</th>
                    <th>Fecha Fin</th>
                    <th>Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for p in periodos %}
                <tr>
                    <td class="text-muted small">{{ p.id_periodo }}</td>
                    <td>{{ p.fecha_inicio|date('d/m/Y') }}</td>
                    <td>{{ p.fecha_fin|date('d/m/Y') }}</td>
                    <td>
                        {% if p.estado == 'abierto' %}
                            <span class="badge bg-success">Abierto</span>
                        {% else %}
                            <span class="badge bg-secondary">Cerrado</span>
                        {% endif %}
                    </td>
                    <td class="text-center">
                        <a href="{{ path_for('periodos.edit', {'id': p.id_periodo}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                        {% if p.estado == 'abierto' %}
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Eliminar"
                                data-bs-toggle="modal"
                                data-bs-target="#modalEliminar"
                                data-id="{{ p.id_periodo }}"
                                data-nombre="{{ p.fecha_inicio }} – {{ p.fecha_fin }}">
                            <i class="bi bi-trash3"></i>
                        </button>
                        {% endif %}
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Total: {{ periodos|length }} período(s).
    </div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el período <strong id="modalNombrePeriodo"></strong>?
                Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn    = event.relatedTarget;
    const id     = btn.getAttribute('data-id');
    const nombre = btn.getAttribute('data-nombre');
    document.getElementById('modalNombrePeriodo').textContent = nombre;
    document.getElementById('formEliminar').action = '/mantenimientos/periodos/' + id + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 4: Create templates/periodos/form.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item"><a href="{{ path_for('periodos.index') }}">Períodos</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nuevo' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-calendar-range me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        {% for error in errores %}<li>{{ error }}</li>{% endfor %}
                    </ul>
                </div>
                {% endif %}

                <div class="alert alert-info small">
                    <i class="bi bi-info-circle me-1"></i>
                    Solo se aceptan períodos del <strong>1 al 15</strong> o del
                    <strong>16 al último día</strong> del mismo mes.
                </div>

                {% if accion == 'crear' %}
                    {% set form_action = path_for('periodos.store') %}
                {% else %}
                    {% set form_action = path_for('periodos.update', {'id': periodo.id_periodo}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="fecha_inicio" class="form-label fw-semibold">
                            Fecha inicio <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="fecha_inicio"
                               name="fecha_inicio" required
                               value="{{ periodo.fecha_inicio ?? '' }}">
                    </div>

                    <div class="mb-3">
                        <label for="fecha_fin" class="form-label fw-semibold">
                            Fecha fin <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="fecha_fin"
                               name="fecha_fin" required
                               value="{{ periodo.fecha_fin ?? '' }}">
                    </div>

                    {% if accion == 'editar' %}
                    <div class="mb-3">
                        <label for="estado" class="form-label fw-semibold">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="abierto"  {{ periodo.estado == 'abierto'  ? 'selected' : '' }}>Abierto</option>
                            <option value="cerrado"  {{ periodo.estado == 'cerrado'  ? 'selected' : '' }}>Cerrado</option>
                        </select>
                        <div class="form-text text-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Un período cerrado no puede reabrirse.
                        </div>
                    </div>
                    {% endif %}

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ path_for('periodos.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>
                            {{ accion == 'crear' ? 'Crear Período' : 'Actualizar Período' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/PeriodosController.php templates/periodos/
git commit -m "feat: add PeriodosController and Twig templates (index, form)"
```

---

## Task 11: FeriadosRepository

**Files:**
- Create: `src/Repositories/FeriadosRepository.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class FeriadosRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        return $this->pdo->query(
            'SELECT id_feriado, fecha, nombre, tipo FROM feriados ORDER BY fecha'
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_feriado, fecha, nombre, tipo FROM feriados WHERE id_feriado = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function insert(string $fecha, string $nombre, string $tipo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO feriados (fecha, nombre, tipo) VALUES (:fecha, :nombre, :tipo)'
        );
        $stmt->execute([':fecha' => $fecha, ':nombre' => $nombre, ':tipo' => $tipo]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $fecha, string $nombre, string $tipo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE feriados SET fecha = :fecha, nombre = :nombre, tipo = :tipo
             WHERE id_feriado = :id'
        );
        $stmt->execute([':fecha' => $fecha, ':nombre' => $nombre, ':tipo' => $tipo, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM feriados WHERE id_feriado = :id');
        $stmt->execute([':id' => $id]);
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Repositories/FeriadosRepository.php
```

Expected: `No syntax errors detected in src/Repositories/FeriadosRepository.php`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/FeriadosRepository.php
git commit -m "feat: add FeriadosRepository"
```

---

## Task 12: FeriadosService

**Files:**
- Create: `src/Services/FeriadosService.php`

- [ ] **Step 1: Create the file**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class FeriadosService
{
    private const TIPOS_VALIDOS = ['obligatorio_pago', 'no_obligatorio_pago'];

    public function __construct(
        private readonly FeriadosRepository  $repo,
        private readonly AuditoriaRepository $auditoriaRepo
    ) {}

    public function listar(): array
    {
        return $this->repo->findAll();
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Feriado no encontrado.');
        }
        return $row;
    }

    public function crear(array $datos, int $loggedInId, string $ip): void
    {
        [$fecha, $nombre, $tipo] = $this->validar($datos);

        try {
            $newId = $this->repo->insert($fecha, $nombre, $tipo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un feriado registrado para esa fecha.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'feriados', $newId, $ip);
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        [$fecha, $nombre, $tipo] = $this->validar($datos);

        try {
            $this->repo->update($id, $fecha, $nombre, $tipo);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) {
                throw new InvalidArgumentException('Ya existe un feriado registrado para esa fecha.');
            }
            throw $e;
        }

        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'feriados', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'feriados', $id, $ip);
    }

    private function validar(array $datos): array
    {
        $fecha  = trim($datos['fecha']  ?? '');
        $nombre = trim($datos['nombre'] ?? '');
        $tipo   = trim($datos['tipo']   ?? '');

        if ($fecha === '') {
            throw new InvalidArgumentException('La fecha es obligatoria.');
        }
        if (DateTimeImmutable::createFromFormat('Y-m-d', $fecha) === false) {
            throw new InvalidArgumentException('Formato de fecha inválido. Use YYYY-MM-DD.');
        }
        if ($nombre === '') {
            throw new InvalidArgumentException('El nombre del feriado es obligatorio.');
        }
        if (mb_strlen($nombre) > 100) {
            throw new InvalidArgumentException('El nombre no puede exceder 100 caracteres.');
        }
        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            throw new InvalidArgumentException('El tipo de feriado no es válido.');
        }

        return [$fecha, $nombre, $tipo];
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Services/FeriadosService.php
```

Expected: `No syntax errors detected in src/Services/FeriadosService.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/FeriadosService.php
git commit -m "feat: add FeriadosService with date/tipo validation and audit logging"
```

---

## Task 13: FeriadosController + templates

**Files:**
- Create: `src/Controllers/FeriadosController.php`
- Create: `templates/feriados/index.html.twig`
- Create: `templates/feriados/form.html.twig`

- [ ] **Step 1: Create FeriadosController**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FeriadosService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Views\Twig;

class FeriadosController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly FeriadosService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'feriados/index.html.twig', [
            'titulo'       => 'Feriados Nacionales',
            'feriados'     => $this->service->listar(),
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'feriados/form.html.twig', [
            'titulo'   => 'Registrar Feriado',
            'accion'   => 'crear',
            'feriado'  => [],
            'errores'  => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Feriado registrado exitosamente.';
            return $response->withHeader('Location', '/mantenimientos/feriados')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'feriados/form.html.twig', [
                'titulo'  => 'Registrar Feriado',
                'accion'  => 'crear',
                'feriado' => $datos,
                'errores' => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $feriado = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Feriado no encontrado.';
            return $response->withHeader('Location', '/mantenimientos/feriados')->withStatus(302);
        }

        return $this->twig->render($response, 'feriados/form.html.twig', [
            'titulo'  => 'Editar Feriado',
            'accion'  => 'editar',
            'feriado' => $feriado,
            'errores' => [],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id         = (int)$args['id'];
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->actualizar($id, $datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Feriado actualizado correctamente.';
            return $response->withHeader('Location', '/mantenimientos/feriados')->withStatus(302);
        } catch (InvalidArgumentException $e) {
            return $this->twig->render($response->withStatus(422), 'feriados/form.html.twig', [
                'titulo'  => 'Editar Feriado',
                'accion'  => 'editar',
                'feriado' => array_merge(['id_feriado' => $id], $datos),
                'errores' => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/mantenimientos/feriados')->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Feriado eliminado correctamente.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/mantenimientos/feriados')->withStatus(302);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Check syntax**

```bash
php -l src/Controllers/FeriadosController.php
```

Expected: `No syntax errors detected in src/Controllers/FeriadosController.php`

- [ ] **Step 3: Create templates/feriados/index.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Feriados Nacionales{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item active">Feriados</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">
        <i class="bi bi-calendar-x me-2 text-primary"></i>Feriados Nacionales
    </h2>
    <a href="{{ path_for('feriados.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nuevo Feriado
    </a>
</div>

{% if feriados|length == 0 %}
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No hay feriados registrados.
</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for f in feriados %}
                <tr>
                    <td class="text-muted small">{{ f.id_feriado }}</td>
                    <td>{{ f.fecha|date('d/m/Y') }}</td>
                    <td class="fw-semibold">{{ f.nombre }}</td>
                    <td>
                        {% if f.tipo == 'obligatorio_pago' %}
                            <span class="badge bg-danger">Obligatorio con pago</span>
                        {% else %}
                            <span class="badge bg-secondary">No obligatorio con pago</span>
                        {% endif %}
                    </td>
                    <td class="text-center">
                        <a href="{{ path_for('feriados.edit', {'id': f.id_feriado}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar">
                            <i class="bi bi-pencil-square"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Eliminar"
                                data-bs-toggle="modal"
                                data-bs-target="#modalEliminar"
                                data-id="{{ f.id_feriado }}"
                                data-nombre="{{ f.nombre }}">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Total: {{ feriados|length }} feriado(s).
    </div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar el feriado <strong id="modalNombreFeriado"></strong>?
                Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash3 me-1"></i>Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn    = event.relatedTarget;
    const id     = btn.getAttribute('data-id');
    const nombre = btn.getAttribute('data-nombre');
    document.getElementById('modalNombreFeriado').textContent = '"' + nombre + '"';
    document.getElementById('formEliminar').action = '/mantenimientos/feriados/' + id + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 4: Create templates/feriados/form.html.twig**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ path_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Mantenimientos</li>
        <li class="breadcrumb-item"><a href="{{ path_for('feriados.index') }}">Feriados</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nuevo' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-calendar-x me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        {% for error in errores %}<li>{{ error }}</li>{% endfor %}
                    </ul>
                </div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = path_for('feriados.store') %}
                {% else %}
                    {% set form_action = path_for('feriados.update', {'id': feriado.id_feriado}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="fecha" class="form-label fw-semibold">
                            Fecha <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control" id="fecha"
                               name="fecha" required
                               value="{{ feriado.fecha ?? '' }}">
                    </div>

                    <div class="mb-3">
                        <label for="nombre" class="form-label fw-semibold">
                            Nombre <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control" id="nombre"
                               name="nombre" maxlength="100" required
                               placeholder="Ej: Día de Juan Santamaría"
                               value="{{ feriado.nombre ?? '' }}">
                    </div>

                    <div class="mb-3">
                        <label for="tipo" class="form-label fw-semibold">
                            Tipo <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" id="tipo" name="tipo" required>
                            <option value="">— Seleccione —</option>
                            <option value="obligatorio_pago"
                                {{ (feriado.tipo ?? '') == 'obligatorio_pago' ? 'selected' : '' }}>
                                Obligatorio con pago
                            </option>
                            <option value="no_obligatorio_pago"
                                {{ (feriado.tipo ?? '') == 'no_obligatorio_pago' ? 'selected' : '' }}>
                                No obligatorio con pago
                            </option>
                        </select>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ path_for('feriados.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>
                            {{ accion == 'crear' ? 'Guardar Feriado' : 'Actualizar Feriado' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/FeriadosController.php templates/feriados/
git commit -m "feat: add FeriadosController and Twig templates (index, form)"
```

---

## Task 14: Wire dependencies.php + routes.php + update navbar

**Files:**
- Modify: `config/dependencies.php`
- Modify: `config/routes.php`
- Modify: `templates/layouts/base.html.twig`

- [ ] **Step 1: Replace config/dependencies.php**

```php
<?php

declare(strict_types=1);

use App\Database\Connection;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

$settings = require __DIR__ . '/settings.php';

return [

    'settings' => $settings,

    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $cfg    = $c->get('settings')['logger'];
        $level  = Level::fromName(ucfirst($cfg['level']));
        $logger = new Logger($cfg['name']);
        $logger->pushHandler(new StreamHandler($cfg['path'], $level));
        return $logger;
    },

    PDO::class => function (ContainerInterface $c): PDO {
        return Connection::create($c->get('settings')['db']);
    },

    Twig::class => function (ContainerInterface $c): Twig {
        $cfg  = $c->get('settings')['twig'];
        $opts = ['auto_reload' => $cfg['auto_reload']];

        if ($cfg['debug']) {
            $opts['debug'] = true;
        } else {
            $opts['cache'] = $cfg['cache_path'];
        }

        $twig = Twig::create($cfg['template_path'], $opts);
        $env  = $twig->getEnvironment();

        if ($cfg['debug']) {
            $env->addExtension(new \Twig\Extension\DebugExtension());
        }

        $env->addGlobal('session',   $_SESSION ?? []);
        $env->addGlobal('app_name',  $c->get('settings')['app']['name']);

        return $twig;
    },

    // ── Repositories ──────────────────────────────────────
    \App\Repositories\AuditoriaRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AuditoriaRepository(
            $c->get(PDO::class),
            $c->get(LoggerInterface::class)
        );
    },

    \App\Repositories\UsuariosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\UsuariosRepository($c->get(PDO::class));
    },

    \App\Repositories\PuestosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PuestosRepository($c->get(PDO::class));
    },

    \App\Repositories\PeriodosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PeriodosRepository($c->get(PDO::class));
    },

    \App\Repositories\FeriadosRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\FeriadosRepository($c->get(PDO::class));
    },

    // ── Services ──────────────────────────────────────────
    \App\Services\AuthService::class => function (ContainerInterface $c) {
        return new \App\Services\AuthService(
            $c->get(\App\Repositories\UsuariosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\UsuariosService::class => function (ContainerInterface $c) {
        return new \App\Services\UsuariosService(
            $c->get(\App\Repositories\UsuariosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\PuestosService::class => function (ContainerInterface $c) {
        return new \App\Services\PuestosService(
            $c->get(\App\Repositories\PuestosRepository::class)
        );
    },

    \App\Services\PeriodosService::class => function (ContainerInterface $c) {
        return new \App\Services\PeriodosService(
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    \App\Services\FeriadosService::class => function (ContainerInterface $c) {
        return new \App\Services\FeriadosService(
            $c->get(\App\Repositories\FeriadosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },

    // ── Controllers ───────────────────────────────────────
    \App\Controllers\AuthController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AuthController(
            $c->get(Twig::class),
            $c->get(\App\Services\AuthService::class)
        );
    },

    \App\Controllers\DashboardController::class => function (ContainerInterface $c) {
        return new \App\Controllers\DashboardController($c->get(Twig::class));
    },

    \App\Controllers\PuestosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PuestosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PuestosService::class)
        );
    },

    \App\Controllers\UsuariosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\UsuariosController(
            $c->get(Twig::class),
            $c->get(\App\Services\UsuariosService::class)
        );
    },

    \App\Controllers\PeriodosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PeriodosController(
            $c->get(Twig::class),
            $c->get(\App\Services\PeriodosService::class)
        );
    },

    \App\Controllers\FeriadosController::class => function (ContainerInterface $c) {
        return new \App\Controllers\FeriadosController(
            $c->get(Twig::class),
            $c->get(\App\Services\FeriadosService::class)
        );
    },

];
```

- [ ] **Step 2: Replace config/routes.php**

```php
<?php

declare(strict_types=1);

use App\Controllers\FeriadosController;
use App\Controllers\PeriodosController;
use App\Controllers\PuestosController;
use App\Controllers\UsuariosController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {

    $app->get('/', function ($request, $response) {
        return $response->withHeader('Location', '/login')->withStatus(302);
    });

    // ── Auth (sin protección) ─────────────────────────────
    $app->get('/login',  [\App\Controllers\AuthController::class, 'showLogin'])->setName('auth.login');
    $app->post('/login', [\App\Controllers\AuthController::class, 'login']);
    $app->get('/logout', [\App\Controllers\AuthController::class, 'logout'])->setName('auth.logout');

    // ── Dashboard ─────────────────────────────────────────
    $app->get('/dashboard', [\App\Controllers\DashboardController::class, 'index'])
        ->setName('dashboard')
        ->add(new AuthMiddleware());

    // ── Mantenimientos (solo admin) ───────────────────────
    $app->group('/mantenimientos', function (RouteCollectorProxy $group) {

        // Puestos
        $group->get('/puestos',                [PuestosController::class, 'index'])->setName('puestos.index');
        $group->get('/puestos/crear',          [PuestosController::class, 'create'])->setName('puestos.create');
        $group->post('/puestos/crear',         [PuestosController::class, 'store'])->setName('puestos.store');
        $group->get('/puestos/{id}/editar',    [PuestosController::class, 'edit'])->setName('puestos.edit');
        $group->post('/puestos/{id}/editar',   [PuestosController::class, 'update'])->setName('puestos.update');
        $group->post('/puestos/{id}/eliminar', [PuestosController::class, 'destroy'])->setName('puestos.destroy');

        // Períodos de Pago
        $group->get('/periodos',                [PeriodosController::class, 'index'])->setName('periodos.index');
        $group->get('/periodos/crear',          [PeriodosController::class, 'create'])->setName('periodos.create');
        $group->post('/periodos/crear',         [PeriodosController::class, 'store'])->setName('periodos.store');
        $group->get('/periodos/{id}/editar',    [PeriodosController::class, 'edit'])->setName('periodos.edit');
        $group->post('/periodos/{id}/editar',   [PeriodosController::class, 'update'])->setName('periodos.update');
        $group->post('/periodos/{id}/eliminar', [PeriodosController::class, 'destroy'])->setName('periodos.destroy');

        // Feriados
        $group->get('/feriados',                [FeriadosController::class, 'index'])->setName('feriados.index');
        $group->get('/feriados/crear',          [FeriadosController::class, 'create'])->setName('feriados.create');
        $group->post('/feriados/crear',         [FeriadosController::class, 'store'])->setName('feriados.store');
        $group->get('/feriados/{id}/editar',    [FeriadosController::class, 'edit'])->setName('feriados.edit');
        $group->post('/feriados/{id}/editar',   [FeriadosController::class, 'update'])->setName('feriados.update');
        $group->post('/feriados/{id}/eliminar', [FeriadosController::class, 'destroy'])->setName('feriados.destroy');

        // Usuarios
        $group->get('/usuarios',                  [UsuariosController::class, 'index'])->setName('usuarios.index');
        $group->get('/usuarios/crear',            [UsuariosController::class, 'create'])->setName('usuarios.create');
        $group->post('/usuarios/crear',           [UsuariosController::class, 'store'])->setName('usuarios.store');
        $group->get('/usuarios/{id}/editar',      [UsuariosController::class, 'edit'])->setName('usuarios.edit');
        $group->post('/usuarios/{id}/editar',     [UsuariosController::class, 'update'])->setName('usuarios.update');
        $group->post('/usuarios/{id}/eliminar',   [UsuariosController::class, 'destroy'])->setName('usuarios.destroy');
        $group->get('/usuarios/{id}/password',    [UsuariosController::class, 'showPasswordReset'])->setName('usuarios.password');
        $group->post('/usuarios/{id}/password',   [UsuariosController::class, 'updatePassword'])->setName('usuarios.updatePassword');

    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Empleados (solo admin) — pendiente ────────────────
    $app->group('/empleados', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Nóminas (solo admin) — pendiente ─────────────────
    $app->group('/nominas', function (RouteCollectorProxy $group) {
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Portal colaborador — pendiente ────────────────────
    $app->group('/mi-perfil', function (RouteCollectorProxy $group) {
    })->add(new AuthMiddleware());

};
```

- [ ] **Step 3: Update the navbar in templates/layouts/base.html.twig**

Replace the three `href="#"` links in the Mantenimientos dropdown with named routes:

```twig
{# Replace this: #}
<a class="dropdown-item" href="#">
    <i class="bi bi-calendar-range me-1"></i>Períodos de Pago
</a>
...
<a class="dropdown-item" href="#">
    <i class="bi bi-calendar-x me-1"></i>Feriados
</a>
...
<a class="dropdown-item" href="#">
    <i class="bi bi-person-gear me-1"></i>Usuarios
</a>

{# With this: #}
<a class="dropdown-item" href="{{ path_for('periodos.index') }}">
    <i class="bi bi-calendar-range me-1"></i>Períodos de Pago
</a>
...
<a class="dropdown-item" href="{{ path_for('feriados.index') }}">
    <i class="bi bi-calendar-x me-1"></i>Feriados
</a>
...
<a class="dropdown-item" href="{{ path_for('usuarios.index') }}">
    <i class="bi bi-person-gear me-1"></i>Usuarios
</a>
```

- [ ] **Step 4: Check syntax on both PHP files**

```bash
php -l config/dependencies.php && php -l config/routes.php
```

Expected:
```
No syntax errors detected in config/dependencies.php
No syntax errors detected in config/routes.php
```

- [ ] **Step 5: Commit**

```bash
git add config/dependencies.php config/routes.php templates/layouts/base.html.twig
git commit -m "feat: wire all new controllers, services, and repositories; update navbar links"
```

---

## Task 15: End-to-end smoke test

No code changes — manual browser verification. XAMPP must be running with Apache and MySQL.

- [ ] **Step 1: Verify all classes load (no fatal errors)**

```bash
php -r "
require 'vendor/autoload.php';
\$settings = require 'config/settings.php';
echo 'Settings OK' . PHP_EOL;
"
```

Expected: `Settings OK`

- [ ] **Step 2: Test login with real credentials**

Open `http://localhost/<project-folder>/public/` in a browser.

- Enter username `admin`, password `password123` → should redirect to `/dashboard`
- Check navbar shows admin menu with Mantenimientos dropdown
- Click Logout → should redirect to `/login`
- Try wrong password → should show "Credenciales incorrectas." flash error

- [ ] **Step 3: Verify audit log captured login/logout**

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root -e "SELECT id_auditoria, id_usuario, accion, tabla_afectada, ip_origen, fecha_accion FROM lubrimotos_nomina.auditoria ORDER BY fecha_accion DESC LIMIT 5;"
```

Expected: rows with `accion = 'LOGIN'` and `accion = 'LOGOUT'`.

- [ ] **Step 4: Test Usuarios CRUD**

Navigate to Mantenimientos → Usuarios:
- Create a new user with username `test_user`, rol `empleado`, password `test` → should fail (< 8 chars)
- Create with password `testpass123` → should succeed with flash success
- Edit the user → change rol → should save
- Try to delete `admin` (your own account) → should show error "No puedes eliminar tu propia cuenta."
- Reset password for `jperez` → enter `newpass123` → should succeed

- [ ] **Step 5: Test Períodos CRUD**

Navigate to Mantenimientos → Períodos de Pago:
- Create with pre-filled dates (1–15 of current month) → should succeed
- Try to create the same period again → should fail with "Ya existe un período que se cruza con esas fechas."
- Try to create a period from day 3 to 20 → should fail with quincenal validation error
- Edit an existing period → change estado to `cerrado` → should succeed
- Try to reopen the closed period → should fail with "Un período cerrado no puede reabrirse."

- [ ] **Step 6: Test Feriados CRUD**

Navigate to Mantenimientos → Feriados (seed data already loaded):
- Verify the 11 seeded feriados are visible
- Create a new feriado for `2026-06-01` → should succeed
- Try to create another feriado for `2026-06-01` → should fail with duplicate date error
- Edit a feriado → change tipo → should succeed
- Delete a feriado → should succeed

- [ ] **Step 7: Final commit (no code changes needed if all passed)**

If any bugs were found and fixed during smoke test, commit the fixes:

```bash
git add -A
git commit -m "fix: smoke test corrections"
```

---

*Plan complete — 15 tasks, all Auth + Mantenimientos modules.*
