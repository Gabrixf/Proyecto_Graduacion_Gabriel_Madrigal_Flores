# Diseño — Módulo Horas Extra

- **Rama:** `feature/horas-extra` · **Módulo #5** · Deriva de una solicitud aprobada.
- **Patrón:** Controller → Service → Repository.

## 1. Alcance
Registro de horas extra, solo admin. Cada HE **deriva de una solicitud aprobada** de tipo `horas_extra`. El sistema toma `id_empleado` y `fecha` de la solicitud, precarga `cantidad_horas`, auto-resuelve `id_periodo` (periodo abierto que contiene la fecha) y `factor_recargo` (2.00 si la fecha es feriado, 1.50 si no), con override. Una HE por solicitud. Auditado.

## 2. Tabla `horas_extra`
`id_hora_extra` PK, `id_solicitud` FK NOT NULL, `id_empleado` FK, `id_periodo` FK, `fecha` DATE, `cantidad_horas` DECIMAL(4,2), `factor_recargo` DECIMAL(3,2) DEFAULT 1.50.

## 3. Repository (`HorasExtraRepository`)
- `findAll(?int $idPeriodo, ?int $idEmpleado): array` — JOIN empleados; filtros; `ORDER BY fecha DESC`.
- `findById(int): ?array` — + empleado.
- `insert(array): int` (id_solicitud, id_empleado, id_periodo, fecha, cantidad_horas, factor_recargo).
- `update(int $id, float $cantidadHoras, float $factorRecargo): void` (solo monto y factor; la fecha/solicitud no cambian).
- `delete(int): void`.
- `existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool`.
- `findSolicitudesDisponibles(?int $incluirId = null): array` — solicitudes `tipo='horas_extra' AND estado='aprobada'` sin HE asociada (LEFT JOIN horas_extra … IS NULL), más la actual al editar; con empleado.
- `findPeriodoAbiertoPorFecha(string $fecha): ?array`, `esFeriado(string $fecha): bool`.

## 4. Service (`HorasExtraService`) — deps Repo, SolicitudesRepository, AuditoriaRepository
- `listar(?periodo,?empleado)`, `obtener`, `datosFormulario(?incluirId)` (solicitudes disponibles), `crear`, `actualizar`, `eliminar`.
- **crear:** valida solicitud (existe, tipo horas_extra, estado aprobada, no convertida ya). Deriva `id_empleado`+`fecha` de la solicitud. `cantidad_horas` = override del form (>0) o `solicitud.horas`; 0 < c ≤ 99.99. `id_periodo` = periodo abierto que contiene la fecha (error si no hay). `factor_recargo` = override (1.50|2.00) o auto (esFeriado→2.00, si no 1.50). Inserta + audita INSERT.
- **actualizar:** `obtener`; solo `cantidad_horas` (>0, ≤99.99) y `factor_recargo` (override 1.50|2.00 o auto según la fecha existente). update + audita UPDATE.
- **eliminar:** delete + audita DELETE.
- Comparación de factor con tolerancia (`abs($f-1.5)<0.001`).

## 5. Controller — index (filtros periodo+empleado), create/store, edit/update, destroy. `RouteContext::urlFor`, admin-only.

## 6. Templates
- `index`: filtros; tabla *Empleado · Fecha · Horas · Factor · Acciones* (badge "Feriado" cuando factor=2.00).
- `form`:
  - **crear:** select de solicitud aprobada disponible (label "Apellidos, Nombre — dd/mm/YYYY — Xh"); `cantidad_horas` (opcional, nota "vacío = horas de la solicitud"); `factor_recargo` como select: "Automático (según feriado)" (vacío) / "1.50 (ordinaria)" / "2.00 (feriado)".
  - **editar:** muestra empleado+fecha como texto informativo (la solicitud no cambia); `cantidad_horas` (prellenado) + `factor_recargo` select.

## 7. Rutas + navbar
Grupo `/horas-extra` admin-only: index `''`, /crear, /{id}/editar, /{id}/eliminar. Navbar: item "Horas Extra" del dropdown *Operaciones* → `url_for('horas_extra.index')`. Nombres de ruta con prefijo `horas_extra.*`.

## 8. Auditoría INSERT/UPDATE/DELETE sobre `'horas_extra'`.

## 9. Convenciones: `url_for`/`base_path`, `RouteContext::urlFor`, modal `data-url`. Sin `path_for`/`base_url`.
