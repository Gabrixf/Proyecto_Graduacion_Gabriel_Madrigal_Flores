# Diseño — Módulo Solicitudes (unificado)

- **Rama:** `feature/solicitudes`
- **Fecha:** 2026-06-01
- **Rol:** Prerrequisito de Horas Extra (5) y Vacaciones (6); también cubre Permisos (8).
- **Patrón:** Controller → Service → Repository (igual que Empleados/Asistencia).

## 1. Alcance
CRUD de solicitudes laborales (tipo `horas_extra`/`vacaciones`/`permiso`), solo admin. El admin registra la solicitud (nace `pendiente`) y la resuelve con acciones **Aprobar/Rechazar** (sella `fecha_resolucion`, `id_usuario_resuelve`, `observacion_admin`). HE y Vacaciones referenciarán la solicitud aprobada. Toda escritura se audita.

## 2. Tabla `solicitudes`
`id_solicitud` PK, `id_empleado` FK, `tipo` ENUM, `fecha_inicio` DATE, `fecha_fin` DATE NULL, `horas` DECIMAL(5,2) NULL, `motivo` VARCHAR(255) NULL, `estado` ENUM('pendiente','aprobada','rechazada') DEFAULT 'pendiente', `fecha_solicitud` TIMESTAMP DEFAULT NOW, `fecha_resolucion` TIMESTAMP NULL, `id_usuario_resuelve` FK NULL, `observacion_admin` VARCHAR(255) NULL.

## 3. Repository
- `findAll(?string $tipo, ?string $estado): array` — JOIN empleados (nombre, apellidos); filtros opcionales; `ORDER BY fecha_solicitud DESC`.
- `findById(int): ?array` — + empleado.
- `insert(array): int` — columnas: id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo (estado y fecha_solicitud por DEFAULT).
- `update(int, array): void` — actualiza tipo, fecha_inicio, fecha_fin, horas, motivo (no toca estado).
- `resolver(int $id, string $estado, int $idUsuario, ?string $obs): void` — `UPDATE estado=:e, fecha_resolucion=NOW(), id_usuario_resuelve=:u, observacion_admin=:o`.
- `delete(int): void`.
- `countDependientes(int $id): int` — suma de filas en `horas_extra` + `vacaciones` + `permisos` con ese `id_solicitud`.

## 4. Service
Deps: `SolicitudesRepository`, `EmpleadosRepository` (dropdown activos), `AuditoriaRepository`.
- `listar(?tipo,?estado)`, `obtener(id)`, `datosFormulario()` (empleados activos), `crear`, `actualizar` (solo si pendiente), `aprobar(id,loggedInId,ip,?obs)`, `rechazar(...)`, `eliminar(id,loggedInId,ip)`.
- **Validación (`validar` → array de columnas):**
  - `tipo` ∈ {horas_extra,vacaciones,permiso}.
  - `id_empleado` > 0, existe y `estado='activo'`.
  - `fecha_inicio` formato `Y-m-d` válido.
  - **horas_extra:** `horas` numérico > 0 (≤ 999.99); `fecha_fin` = null (día único, se usa fecha_inicio).
  - **vacaciones / permiso:** `fecha_fin` válido y ≥ `fecha_inicio`; `horas` = null.
  - `motivo` opcional ≤ 255.
  - Errores acumulados → `InvalidArgumentException`.
- `actualizar`: si `estado !== 'pendiente'` → `RuntimeException('No se puede editar una solicitud ya resuelta.')`.
- `aprobar`/`rechazar`: `obtener`; si `estado !== 'pendiente'` → `RuntimeException('La solicitud ya fue resuelta.')`; `resolver(...)`; auditoría UPDATE.
- `eliminar`: si `estado === 'aprobada'` → `RuntimeException` (las aprobadas pueden tener registros derivados); si `countDependientes > 0` → `RuntimeException`; si no, `delete` + auditoría DELETE.

## 5. Controller
`index` (filtros `?tipo=`, `?estado=`), `create`/`store`, `edit`/`update` (si resuelta → flash error + redirect), `aprobar`/`rechazar` (POST, leen `observacion` del body), `destroy`. `RouteContext::urlFor`. Admin-only. `loggedInId`+ip a Service.

## 6. Templates
- **index:** filtros tipo+estado; tabla *Empleado · Tipo · Inicio · Fin · Horas · Estado (badge) · Acciones*. Acciones por estado: Editar + Aprobar + Rechazar solo si `pendiente`; Eliminar si `estado != 'aprobada'`. Modal de resolución (un solo modal reutilizado para aprobar/rechazar, con textarea `observacion` y action seteada por JS vía `data-url`).
- **form:** empleado (select), tipo (select), fecha_inicio, fecha_fin, horas, motivo. Notas: "horas: solo horas extra", "fecha fin: vacaciones/permiso". Repobla con `solicitud.*`.

## 7. Rutas + navbar
Grupo `/solicitudes` admin-only (index `''`, /crear, /{id}/editar, /{id}/eliminar, /{id}/aprobar, /{id}/rechazar). Navbar: item "Solicitudes" al inicio del dropdown *Operaciones* → `url_for('solicitudes.index')`.

## 8. Auditoría
INSERT/UPDATE/DELETE sobre `'solicitudes'`; aprobar/rechazar registran UPDATE.

## 9. Convenciones
`url_for`/`base_path` en plantillas; `RouteContext::fromRequest(...)->getRouteParser()->urlFor()` en el controller. Sin `path_for`/`base_url`/`Location` hardcodeado; modal de borrado/resolución usa `data-url`.
