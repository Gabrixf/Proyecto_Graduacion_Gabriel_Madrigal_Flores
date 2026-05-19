# Design Spec: Auth + Mantenimientos (Módulos 1 & 2)

**Date:** 2026-05-19
**Project:** Sistema de Gestión de Nómina — Lubrimotos del Sur
**Author:** Gabriel Iván Madrigal Flores
**Scope:** Módulo 1 (Auth/Seguridad) + Módulo 2 (Mantenimientos: Periodos, Feriados, Usuarios)

---

## 1. Context

The existing codebase has a stub `AuthController` that accepts any credentials and hardcodes `admin` as the role. The `Puestos` CRUD is the only fully implemented module and serves as the reference pattern. This spec replaces the stub with real authentication and adds three new maintenance modules.

---

## 2. Architecture

### 2.1 Approach

Option A (shared `UsuariosRepository`) was selected. `AuthService` and `UsuariosService` both inject the same `UsuariosRepository`. A single `AuditoriaRepository` (append-only) is injected into every service that writes audit records.

No service calls another service. Controllers are the only layer that reads `$_SERVER` and `$_SESSION`. The logged-in user's ID (`$_SESSION['usuario_id']`) is read by the Controller and passed explicitly to the Service for audit logging — Services never access `$_SESSION` directly.

### 2.2 New files

```
src/
├── Repositories/
│   ├── UsuariosRepository.php
│   └── AuditoriaRepository.php
├── Services/
│   ├── AuthService.php
│   ├── UsuariosService.php
│   ├── PeriodosService.php
│   └── FeriadosService.php
├── Controllers/
│   ├── AuthController.php        ← replace stub
│   ├── UsuariosController.php
│   ├── PeriodosController.php
│   └── FeriadosController.php
templates/
├── usuarios/
│   ├── index.html.twig
│   ├── form.html.twig
│   └── password.html.twig        ← dedicated password reset form
├── periodos/
│   ├── index.html.twig
│   └── form.html.twig
└── feriados/
    ├── index.html.twig
    └── form.html.twig
```

All files use `declare(strict_types=1)`. Classes in PascalCase, methods in camelCase, DB columns in snake_case.

---

## 3. Auth Module

### 3.1 Routes

```
GET  /login   → AuthController::showLogin
POST /login   → AuthController::login
GET  /logout  → AuthController::logout
```

No auth middleware on these routes.

### 3.2 Login flow

```
POST /login
  AuthController reads nombre_usuario + contrasena from parsed body
  Passes ip_origen = $_SERVER['REMOTE_ADDR'] to AuthService

  AuthService::verificarCredenciales(string $usuario, string $password, string $ip): array
    UsuariosRepository::findByNombreUsuario($usuario)
      → null OR activo = 0 → throw InvalidArgumentException('Credenciales incorrectas.')
    password_verify($password, $row['contrasena_hash'])
      → false → throw InvalidArgumentException('Credenciales incorrectas.')
    AuditoriaRepository::insert('LOGIN', $row['id_usuario'], 'usuarios', null, $ip)
    return ['id_usuario', 'nombre_usuario', 'rol']

  Controller sets:
    $_SESSION['usuario_id']     = $data['id_usuario']
    $_SESSION['usuario_nombre'] = $data['nombre_usuario']
    $_SESSION['usuario_rol']    = $data['rol']
  Redirect → /dashboard (both roles)
```

Error message is identical whether the username does not exist or the password is wrong — prevents user enumeration.

### 3.3 Logout flow

```
GET /logout
  AuthService::cerrarSesion(int $userId, string $ip): void
    AuditoriaRepository::insert('LOGOUT', $userId, 'usuarios', null, $ip)
  Controller: session_unset() + session_destroy()
  Redirect → /login
```

### 3.4 Session variables

| Variable | Value |
|---|---|
| `$_SESSION['usuario_id']` | INT — primary key of `usuarios` |
| `$_SESSION['usuario_nombre']` | STRING — `nombre_usuario` |
| `$_SESSION['usuario_rol']` | STRING — `'admin'` or `'empleado'` |

---

## 4. Mantenimientos — Usuarios

### 4.1 Routes (admin only)

```
GET    /mantenimientos/usuarios                        → index
GET    /mantenimientos/usuarios/crear                  → create
POST   /mantenimientos/usuarios/crear                  → store
GET    /mantenimientos/usuarios/{id}/editar            → edit
POST   /mantenimientos/usuarios/{id}/editar            → update
POST   /mantenimientos/usuarios/{id}/eliminar          → destroy
GET    /mantenimientos/usuarios/{id}/password          → showPasswordReset
POST   /mantenimientos/usuarios/{id}/password          → updatePassword
```

### 4.2 Create

- `nombre_usuario`: required, unique (catch SQLSTATE 23000 → "El nombre de usuario ya existe.")
- `rol`: must be `'admin'` or `'empleado'`
- `contrasena`: required, min 8 characters
- Hash: `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12])`
- Audit: `INSERT` on `usuarios`

### 4.3 Edit

Editable fields: `nombre_usuario`, `rol`, `activo`.

Guard: an admin cannot change their own `rol` or `activo`. The Controller passes `$_SESSION['usuario_id']` as `$loggedInId` to `UsuariosService::actualizar($id, $datos, $loggedInId)`. If `$id === $loggedInId` and `rol` or `activo` is being changed → `InvalidArgumentException('No puedes modificar tu propio rol o estado.')`.

Audit: `UPDATE` on `usuarios`.

### 4.4 Password reset

Separate form at `/mantenimientos/usuarios/{id}/password`.

- New password: required, min 8 characters
- Hash with bcrypt cost 12
- `UsuariosRepository::updatePassword(int $id, string $hash)`
- Audit: `UPDATE` on `usuarios`

### 4.5 Delete

- Check `UsuariosRepository::hasLinkedEmpleado(int $id)` — if true → `InvalidArgumentException('No se puede eliminar: el usuario tiene un empleado asociado.')`
- Guard: cannot delete own account — Controller passes `$_SESSION['usuario_id']` as `$loggedInId` to `UsuariosService::eliminar($id, $loggedInId)`; if equal → `InvalidArgumentException('No puedes eliminar tu propia cuenta.')`.
- Audit: `DELETE` on `usuarios`

---

## 5. Mantenimientos — Períodos de Pago

### 5.1 Routes (admin only)

```
GET    /mantenimientos/periodos
GET    /mantenimientos/periodos/crear
POST   /mantenimientos/periodos/crear
GET    /mantenimientos/periodos/{id}/editar
POST   /mantenimientos/periodos/{id}/editar
POST   /mantenimientos/periodos/{id}/eliminar
```

### 5.2 Quincenal validation

`PeriodosService` validates every insert and update:

**Rule 1 — Valid quincenal range:**
- `fecha_inicio` day = 1 AND `fecha_fin` day = 15, same month/year. OR
- `fecha_inicio` day = 16 AND `fecha_fin` = last day of that month, same month/year.
- Any other combination → `InvalidArgumentException('El período debe ser del 1 al 15 o del 16 al último día del mes.')`

**Rule 2 — No overlapping periods:**
- `PeriodosRepository::findOverlapping($fechaInicio, $fechaFin, $excludeId)`
- Any result → `InvalidArgumentException('Ya existe un período que se cruza con esas fechas.')`

**Rule 3 — Estado transition:**
- `estado` can only move `abierto → cerrado`, never `cerrado → abierto`.
- Attempting to reopen → `InvalidArgumentException('Un período cerrado no puede reabrirse.')`

### 5.3 Form UX

The create form pre-fills `fecha_inicio` and `fecha_fin` with the next expected quincenal dates (calculated from the most recent period in the DB). The admin can see the suggestion but validation still enforces Rule 1 server-side.

### 5.4 Delete

Only allowed if `estado = 'abierto'` and no `asistencia`, `horas_extra`, `vacaciones`, `incapacidades`, or `permisos` rows reference the period. Otherwise → `InvalidArgumentException`.

---

## 6. Mantenimientos — Feriados

### 6.1 Routes (admin only)

```
GET    /mantenimientos/feriados
GET    /mantenimientos/feriados/crear
POST   /mantenimientos/feriados/crear
GET    /mantenimientos/feriados/{id}/editar
POST   /mantenimientos/feriados/{id}/editar
POST   /mantenimientos/feriados/{id}/eliminar
```

### 6.2 Validation

- `fecha`: required, valid date, unique (catch SQLSTATE 23000 → "Ya existe un feriado registrado para esa fecha.")
- `nombre`: required, max 100 chars
- `tipo`: must be `'obligatorio_pago'` or `'no_obligatorio_pago'`

### 6.3 Delete

No foreign key restrictions block deletion (the `asistencia` FK to `feriados` is `ON DELETE SET NULL`). Delete is always allowed.

---

## 7. AuditoriaRepository

Append-only. Single public method:

```php
public function insert(
    string $accion,       // 'INSERT'|'UPDATE'|'DELETE'|'LOGIN'|'LOGOUT'
    int    $idUsuario,
    string $tablaAfectada,
    ?int   $idRegistro,
    string $ipOrigen,
    ?string $detalle = null
): void
```

Failures are caught internally, logged via Monolog, and swallowed — audit errors must never break the main operation.

---

## 8. Error handling summary

| Exception | Thrown by | Caught by | Result |
|---|---|---|---|
| `InvalidArgumentException` | Service (validation) | Controller | Re-render form with error message |
| `RuntimeException` | Service/Repository (not found) | Controller | Redirect with `flash_error` |
| PDO SQLSTATE 23000 | Repository | Repository | Re-thrown as `RuntimeException` with clean message |
| Any exception in Auditoria | AuditoriaRepository | Itself (silent) | Logged via Monolog, swallowed |

---

## 9. Seed fix

`database/seed.sql` contains placeholder bcrypt hashes. Before implementation begins, generate real hashes for `password123` at cost 12 and update the file. Both seed users (`admin`, `jperez`) use this password for local development.

---

## 10. Out of scope

- Password change by the employee themselves (deferred to `/mi-perfil` module)
- "Remember me" / persistent sessions
- PHPUnit tests (deferred until core modules are stable)
- Retroactive audit logging for the existing Puestos module
