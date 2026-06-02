# Diseño — Módulo Permisos

- **Rama:** `feature/permisos` · **Módulo #8** · Deriva de solicitud aprobada `tipo='permiso'`.

## 1. Alcance
Registro de permisos, solo admin. Deriva de una solicitud aprobada de tipo `permiso` (toma id_empleado, fecha_inicio, fecha_fin). Auto-resuelve `id_periodo` (periodo abierto que contiene fecha_inicio). `con_goce_salarial` (1/0) lo marca el admin (por defecto con goce). Un permiso por solicitud. Auditado.

## 2. Tabla `permisos`
id_permiso PK, id_solicitud FK NOT NULL, id_empleado FK, id_periodo FK, fecha_inicio DATE, fecha_fin DATE, con_goce_salarial TINYINT(1) DEFAULT 1.

## 3. Repository (`PermisosRepository`)
- `findAll(?periodo,?empleado)` (JOIN empleados; ORDER BY fecha_inicio DESC), `findById`.
- `insert(array): int` (id_solicitud, id_empleado, id_periodo, fecha_inicio, fecha_fin, con_goce_salarial).
- `update(int $id, int $conGoce): void` (solo el goce; fechas/empleado fijos de la solicitud).
- `delete(int): void`, `existsBySolicitud(id,?excludeId)`, `findSolicitudesDisponibles(?incluirId)` (permiso aprobadas sin permiso), `findPeriodoAbiertoPorFecha(fecha)`.

## 4. Service (`PermisosService`) — deps Repo, SolicitudesRepository, EmpleadosRepository, PeriodosRepository, AuditoriaRepository
- `listar/obtener/datosFormulario(?incluirId)/datosFiltros/crear/actualizar/eliminar` + auditoría.
- **crear:** valida solicitud (permiso, aprobada, con fecha_fin, no convertida); deriva empleado+fechas; resuelve periodo abierto de fecha_inicio (error si no hay); `con_goce_salarial` = 1 si el form envía '1', si no 0.
- **actualizar:** solo cambia `con_goce_salarial`.
- **eliminar:** delete + auditoría.

## 5. Controller — index (filtros periodo+empleado), create/store, edit/update, destroy. `RouteContext::urlFor`, admin-only, 422 re-render.

## 6. Templates
- `index`: filtros; tabla *Empleado · Inicio · Fin · Goce (badge) · Acciones*.
- `form`: crear → select de solicitud permiso disponible + switch goce (patrón hidden+checkbox para que el POST siempre envíe 0/1). editar → empleado/fechas informativos + switch goce.

## 7. Rutas + navbar
Grupo `/permisos` admin-only (index `''`, /crear, /{id}/editar, /{id}/eliminar). Navbar: item "Permisos" del dropdown *Operaciones* → `url_for('permisos.index')`.

## 8. Auditoría INSERT/UPDATE/DELETE sobre `'permisos'`.

## 9. Convenciones `url_for`/`base_path`/`RouteContext::urlFor`/modal `data-url`. Sin `path_for`/`base_url`.
