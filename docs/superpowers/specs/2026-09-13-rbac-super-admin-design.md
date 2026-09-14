# Diseño — Rol Super Admin (jerarquía sobre usuarios y catálogos)

- **Rama:** `feature/super-admin` (a partir de `develop`)
- **Fecha:** 2026-09-13
- **Rol:** Cambio en el núcleo de autorización (RBAC). Toca `usuarios.rol`, `RoleMiddleware`, `routes.php`, `UsuariosService` y las plantillas que hoy comparan `rol == 'admin'` de forma exacta.
- **Patrón:** Controller → Service → Repository (igual que el resto del proyecto). No se crean módulos nuevos.
- **Origen:** Retroalimentación del tutor (Braulio Sandí Morales) al estudiante: debe existir un rol por encima de `admin` que controle usuarios y catálogos.

## 0. Contexto y motivo

Hoy `usuarios.rol` es `ENUM('admin','empleado')` y `RoleMiddleware` compara el rol de forma exacta (`$rolUsuario !== $this->rolRequerido`). Un solo rol `admin` controla tanto la operación diaria de nómina (Empleados, Asistencia, Solicitudes, Horas Extra, Vacaciones, Incapacidades, Permisos, Nóminas, Aguinaldo, Liquidación, Evaluaciones, Reportes) como la configuración de base del sistema (Puestos, Períodos, Feriados, Usuarios — el grupo de rutas `/mantenimientos`).

El tutor pidió separar esto: debe existir un `super_admin` que controle exclusivamente usuarios y catálogos, mientras que un `admin` normal sigue operando el día a día **sin acceso a esa configuración de base**. El `super_admin` puede hacer todo lo que hace un `admin`, más lo exclusivo (jerárquico, no un carril aparte) — confirmado con el estudiante.

## 1. Alcance

1. Nuevo valor `'super_admin'` en `usuarios.rol`, con rango superior a `admin`.
2. `RoleMiddleware` pasa de comparación exacta a comparación por rango jerárquico: `admin` dentro de una barrera `RoleMiddleware('admin')` deja pasar tanto a `admin` como a `super_admin`; una barrera `RoleMiddleware('super_admin')` exige exactamente ese rol (o superior, aunque hoy no hay ninguno por encima).
3. El grupo de rutas `/mantenimientos` (Puestos, Períodos, Feriados, Usuarios) pasa a exigir `super_admin`. Los otros 11 grupos admin-only quedan con `RoleMiddleware('admin')` sin cambios (el super_admin entra igual, por jerarquía).
4. Regla de negocio nueva: el sistema nunca puede quedarse sin ningún `super_admin` activo. `UsuariosService` bloquea degradar de rol, desactivar, o eliminar al **último** `super_admin` con `activo = 1`.
5. UI: sidebar, badge de rol y formulario de Usuarios reflejan el tercer rol.
6. `seed.sql`: el usuario admin sembrado pasa a `super_admin`; se agrega un segundo usuario de prueba con rol `admin` normal.

**Fuera de alcance (YAGNI):**
- Tabla de permisos/ACL granular — con 3 roles y una relación estrictamente jerárquica, un `ENUM` + rango numérico alcanza. No hay necesidad de modelar permisos por acción individual.
- Restringir `/reportes/auditoria` (u otro reporte) a `super_admin` — el tutor pidió específicamente "usuarios y catálogos"; ampliar el alcance a Reportes sería una decisión no pedida. Reportes sigue bajo `admin` normal.
- Script de migración `ALTER TABLE` para preservar datos existentes — el proyecto sigue con datos de prueba (`seed.sql`); se edita `schema.sql` directamente y se reimporta, como ya se hizo para los Grupos 7/8.
- Tocar los módulos operativos (Empleados, Asistencia, Nóminas, etc.) — no cambian de comportamiento, solo dejan de tener un candado distinto al que ya tenían (siguen exigiendo `admin` como mínimo).
- Módulo de Contratos (feedback separado del mismo tutor) — spec propio, fuera de este documento.

## 2. Base de datos — `database/schema.sql`

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

El usuario centinela (`id_usuario=1`, `sin_cuenta`, inactivo) no cambia — sigue en `rol='empleado'`, nunca se usa para login.

`database/seed.sql`:

```sql
-- id_usuario 1 esta reservado por schema.sql para el usuario centinela "sin_cuenta".
INSERT INTO `usuarios` (`id_usuario`, `nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
(2, 'superadmin', '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'super_admin', 1),
(3, 'jperez',     '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'empleado',    1),
(4, 'admin',      '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'admin',       1);
```

Contraseña de prueba para los tres: `password123` (mismo hash ya usado en el seed actual). El `id_usuario=2` conserva su ID — solo cambia de `nombre_usuario`/`rol` — así que la FK `empleados.id_usuario` que ya apunta a él (Emerson, el dueño) no se rompe. `jperez` (`id_usuario=3`) tampoco se mueve. El nuevo `admin` de prueba entra como `id_usuario=4`, sin vincularse a ningún empleado.

## 3. `RoleMiddleware` — jerarquía por rango

```php
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

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $usuario     = $request->getAttribute('usuario', []);
        $rolUsuario  = $usuario['rol'] ?? '';
        $rangoActual = self::RANGOS[$rolUsuario] ?? -1;
        $rangoMinimo = self::RANGOS[$this->rolRequerido] ?? PHP_INT_MAX;

        if ($rangoActual < $rangoMinimo) {
            $_SESSION['flash_error'] = 'No tiene permisos para acceder a esa sección.';
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $response = new Response();
            return $response->withHeader('Location', $routeParser->urlFor('dashboard'))->withStatus(302);
        }

        return $handler->handle($request);
    }
}
```

Un rol desconocido o vacío cae en rango `-1` (nunca pasa ninguna barrera) en vez de romper con un índice inexistente — mismo espíritu defensivo que el resto del middleware.

## 4. `config/routes.php`

Único cambio: la línea de cierre del grupo `/mantenimientos` (Puestos, Períodos, Feriados, Usuarios):

```php
})->add(new RoleMiddleware('super_admin'))->add(new AuthMiddleware());
```

Los otros 11 grupos (`Empleados`, `Asistencia`, `Solicitudes`, `Horas Extra`, `Vacaciones`, `Incapacidades`, `Permisos`, `Nóminas`, `Aguinaldo`, `Liquidación`, `Evaluaciones`, `Reportes`) no se tocan.

## 5. `UsuariosService` — validación de rol y protección del último super_admin

```php
private const ROLES_VALIDOS = ['super_admin', 'admin', 'empleado'];
```

Nuevo método privado, usado desde `actualizar()` y `eliminar()`:

```php
private function bloquearSiEsUltimoSuperAdminActivo(array $actual, bool $dejaDeSerSuperAdminActivo): void
{
    $esSuperAdminActivoHoy = $actual['rol'] === 'super_admin' && (int) $actual['activo'] === 1;

    if ($esSuperAdminActivoHoy && $dejaDeSerSuperAdminActivo && $this->repo->contarSuperAdminsActivos() <= 1) {
        throw new InvalidArgumentException('Debe existir al menos un super_admin activo en el sistema.');
    }
}
```

- `actualizar()`: calcula `$dejaDeSerSuperAdminActivo = ($rol !== 'super_admin') || ($activo !== 1)` con los valores ya validados del formulario, y llama al método **antes** de tocar la BD.
- `eliminar()`: llama al método con `$dejaDeSerSuperAdminActivo = true` (eliminar siempre implica dejar de ser super_admin activo).
- El bloqueo existente de "no puedo modificar mi propia cuenta" (`$id === $loggedInId`) no cambia y sigue evaluándose independientemente — ambas protecciones pueden aplicar a la vez sin conflicto.

Nuevo método en `UsuariosRepository`, mismo estilo que `hasLinkedEmpleado`:

```php
public function contarSuperAdminsActivos(): int
{
    return (int) $this->pdo->query(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'super_admin' AND activo = 1"
    )->fetchColumn();
}
```

## 6. Interfaz

**`templates/layouts/base.html.twig`:**
- `{% if session.usuario_rol == 'admin' %}` → `{% if session.usuario_rol in ['admin', 'super_admin'] %}` (bloque que muestra Dashboard/Empleados/Reportes, Operaciones y Nómina).
- El grupo `"Administración"` (Puestos, Períodos, Feriados, Usuarios) se anida dentro de un `{% if session.usuario_rol == 'super_admin' %}` propio, dentro del bloque anterior — un `admin` normal ya no lo ve en el sidebar.
- Badge de rol: `{{ session.usuario_rol == 'admin' ? 'badge-admin' : 'badge-emp' }}` → se agrega una rama para `super_admin` con una clase nueva `badge-super`.

**`public/assets/css/app.css`:** nueva clase junto a `.badge-admin`/`.badge-emp`:

```css
.badge-super { background: #f1e6ff; color: #6b21a8; }
```

(violeta, para no confundirse visualmente con el tono ámbar ya usado por `.badge-pend`)

**`templates/usuarios/form.html.twig`:** el `<select id="rol">` agrega:

```twig
<option value="super_admin" {{ (usuario.rol ?? '') == 'super_admin' ? 'selected' : '' }}>
    Super Administrador
</option>
```

**`src/Controllers/DashboardController.php`:**

```php
$kpis = in_array($usuario['rol'], ['admin', 'super_admin'], true)
    ? $this->service->kpisAdmin()
    : $this->service->kpisEmpleado((int) $usuario['id']);
```

**`templates/dashboard/index.html.twig`:** el bloque de KPIs también compara de forma exacta (`{% if session.usuario_rol == 'admin' %}`, línea 13) — si no se corrige, un `super_admin` recibiría `kpisAdmin()` del controller pero el template igual le ocultaría la sección de KPIs. Pasa a `{% if session.usuario_rol in ['admin', 'super_admin'] %}`.

**`templates/usuarios/index.html.twig`:** el badge de rol por fila (líneas 59-63) hoy es binario (`{% if u.rol == 'admin' %}Admin{% else %}Empleado{% endif %}`) y mostraría "Empleado" para una fila `super_admin` — incorrecto. Pasa a de tres ramas:

```twig
{% if u.rol == 'super_admin' %}
    <span class="badge bg-dark">Super Admin</span>
{% elseif u.rol == 'admin' %}
    <span class="badge bg-danger">Admin</span>
{% else %}
    <span class="badge bg-secondary">Empleado</span>
{% endif %}
```

## 7. Documentación — `CLAUDE.md`

Se actualiza la sección "Autenticación y RBAC": se documenta el tercer rol, la jerarquía (`super_admin > admin > empleado`), y que `/mantenimientos` (Puestos, Períodos, Feriados, Usuarios) ahora exige `super_admin` mientras el resto de grupos admin siguen aceptando `admin` o superior. Mismo estilo ya usado para documentar los Grupos 7/8 (nota fechada).

## 8. Sin cambios en

- Módulos operativos (Empleados, Asistencia, Solicitudes, Horas Extra, Vacaciones, Incapacidades, Permisos, Nóminas, Aguinaldo, Liquidación, Evaluaciones) — su lógica de negocio no cambia; solo heredan que un `super_admin` también puede entrar.
- `/reportes/*`, incluyendo `/reportes/auditoria` — sigue exigiendo `admin` (no exclusivo de `super_admin`).
- `AuthMiddleware` — sigue adjuntando `['id','nombre','rol']` desde la sesión sin cambios; el valor de `rol` simplemente ahora puede ser `'super_admin'`.
- `AuthController` / `AuthService` — el login no distingue roles, solo autentica.
- Portal del colaborador (autoservicio) — sin cambios, sigue abierto a cualquier usuario autenticado.
- Esquema de las demás 27 tablas.

## 9. Pruebas nuevas a cubrir

- `RoleMiddlewareTest`: una barrera `RoleMiddleware('admin')` deja pasar a un usuario con rol `super_admin`; una barrera `RoleMiddleware('super_admin')` **bloquea** a un usuario con rol `admin` (redirige a dashboard con `flash_error`); un rol vacío o desconocido no pasa ninguna barrera.
- `UsuariosServiceTest`:
  - `crear()` acepta `'super_admin'` como rol válido.
  - `actualizar()`: degradar a `admin` (o desactivar) al único `super_admin` activo lanza `InvalidArgumentException`; si hay dos `super_admin` activos, degradar/desactivar uno de ellos sí se permite.
  - `eliminar()`: eliminar al único `super_admin` activo lanza `InvalidArgumentException`.
  - La protección existente "no puedo modificar mi propia cuenta" sigue funcionando igual para un `super_admin`.
- `UsuariosRepositoryTest` (o el equivalente de integración existente, si lo hay): `contarSuperAdminsActivos()` cuenta solo `rol='super_admin' AND activo=1`.
- `DashboardControllerTest` (si existe): un `super_admin` recibe los KPIs de admin.

## 10. Convenciones

`RouteContext::fromRequest(...)->getRouteParser()->urlFor()` para redirecciones desde middleware — igual que el resto del proyecto. Nombres de rol en `snake_case` (`super_admin`), consistente con el resto de valores `ENUM` del esquema.
