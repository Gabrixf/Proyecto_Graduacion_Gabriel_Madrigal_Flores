# Diseño — Módulo Incapacidades

- **Rama:** `feature/incapacidades` · **Módulo #7** · CRUD directo (sin solicitud/aprobación). Cruza el 50%.

## 1. Alcance
CRUD de incapacidades médicas, solo admin. `tipo` ∈ {CCSS, INS, particular}. `dias` = días naturales del rango (inclusive) con override. `id_periodo` auto-resuelto (periodo abierto que contiene fecha_inicio). `documento_respaldo` es una referencia de texto (no carga de archivo). Auditado.

## 2. Tabla `incapacidades`
id_incapacidad PK, id_empleado FK, id_periodo FK, tipo ENUM('CCSS','INS','particular'), fecha_inicio DATE, fecha_fin DATE, dias INT, documento_respaldo VARCHAR(255) NULL.

## 3. Repository (`IncapacidadesRepository`)
- `findAll(?int $idPeriodo, ?int $idEmpleado, ?string $tipo): array` (JOIN empleados; filtros; ORDER BY fecha_inicio DESC).
- `findById(int): ?array`, `insert(array): int`, `update(int, array): void`, `delete(int): void`.
- `findPeriodoAbiertoPorFecha(string $fecha): ?array`.

## 4. Service (`IncapacidadesService`) — deps Repo, EmpleadosRepository, PeriodosRepository, AuditoriaRepository
- `listar(?periodo,?empleado,?tipo)`, `obtener`, `datosFormulario()` (empleados activos), `datosFiltros()` (empleados+periodos), `crear`, `actualizar`, `eliminar` + auditoría.
- **Validación (`validar` → columnas):** `id_empleado` activo; `tipo` enum; `fecha_inicio`/`fecha_fin` `Y-m-d` válidas y `fin ≥ inicio`; `dias` = override entero/decimal > 0 o (fin − inicio + 1) naturales; `id_periodo` = periodo abierto de fecha_inicio (error si no hay); `documento_respaldo` opcional ≤ 255 (null si vacío). Errores acumulados → `InvalidArgumentException`.

## 5. Controller — index (filtros `?periodo=&empleado=&tipo=`), create/store, edit/update, destroy. `RouteContext::urlFor`, admin-only, 422 re-render.

## 6. Templates
- `index`: filtros (periodo, empleado, tipo); tabla *Empleado · Tipo (badge) · Inicio · Fin · Días · Doc · Acciones*.
- `form`: empleado (select activos), tipo (select), fecha_inicio, fecha_fin, dias (opcional "vacío = días naturales"), documento_respaldo (texto opcional). Periodo se resuelve solo.

## 7. Rutas + navbar
Grupo `/incapacidades` admin-only (index `''`, /crear, /{id}/editar, /{id}/eliminar). Navbar: item "Incapacidades" del dropdown *Operaciones* → `url_for('incapacidades.index')`.

## 8. Auditoría INSERT/UPDATE/DELETE sobre `'incapacidades'`.

## 9. Convenciones `url_for`/`base_path`/`RouteContext::urlFor`/modal `data-url`. Sin `path_for`/`base_url`.
