# Diseño — Módulo Vacaciones

- **Rama:** `feature/vacaciones` · **Módulo #6** · Deriva de solicitud aprobada + actualiza `saldo_vacaciones`.

## 1. Alcance
Registro de vacaciones disfrutadas, solo admin. Deriva de una solicitud aprobada `tipo='vacaciones'` (toma id_empleado, fecha_inicio, fecha_fin). `dias_tomados` = días naturales del rango (inclusive) con override. Auto-resuelve `id_periodo` (periodo abierto que contiene fecha_inicio). **Actualiza `saldo_vacaciones`** del (empleado, año de fecha_inicio): crea el saldo si no existe (ganados=0), suma a `dias_disfrutados`, recalcula `dias_disponibles = dias_ganados − dias_disfrutados` (puede quedar negativo — no se bloquea). Una vacación por solicitud. Auditado.

## 2. Tablas
`vacaciones`: id_vacacion PK, id_solicitud FK NOT NULL, id_empleado FK, id_periodo FK, fecha_inicio DATE, fecha_fin DATE, dias_tomados DECIMAL(4,1).
`saldo_vacaciones`: id_saldo PK, id_empleado FK, anio INT, dias_ganados/dias_disfrutados/dias_disponibles DECIMAL(5,1), UNIQUE(id_empleado, anio).

## 3. Repository (`VacacionesRepository`)
- `findAll(?periodo,?empleado)` (JOIN empleados), `findById(int)`, `existsBySolicitud(id,?excludeId)`, `findSolicitudesDisponibles(?incluirId)` (vacaciones aprobadas sin vacación), `findPeriodoAbiertoPorFecha(fecha)`, `findSaldo(idEmpleado,anio)`.
- `insert(array): int` — **transacción**: INSERT vacaciones + `ajustarSaldo(+dias)`.
- `update(int $id, float $nuevoDias): void` — transacción: lee dias/empleado/año actuales, UPDATE dias_tomados, `ajustarSaldo(delta)`.
- `delete(int): void` — transacción: lee la fila, DELETE, `ajustarSaldo(−dias)`.
- privado `ajustarSaldo(idEmpleado,anio,delta)` — upsert del saldo recalculando disponibles (disfrutados nunca < 0).

## 4. Service (`VacacionesService`) — deps Repo, SolicitudesRepository, EmpleadosRepository, PeriodosRepository, AuditoriaRepository
- `listar/obtener/datosFormulario(?incluirId)/datosFiltros/crear/actualizar/eliminar/saldoDeVacacion(idVacacion)`.
- **crear:** valida solicitud (vacaciones, aprobada, no convertida); deriva empleado+fechas; `dias_tomados` = override (>0) o días naturales inclusive; `id_periodo` = periodo abierto de fecha_inicio (error si no hay); `anio` = año de fecha_inicio. Inserta (transacción con saldo) + audita.
- **actualizar:** solo `dias_tomados` (override o recálculo de las fechas existentes); repo.update ajusta el saldo por el delta; audita.
- **eliminar:** repo.delete revierte el saldo; audita.
- `saldoDeVacacion`: devuelve `{anio, dias_disponibles}` para el aviso post-registro.

## 5. Controller — index (filtros periodo+empleado), create/store, edit/update, destroy. Tras crear/actualizar, **flash de aviso** con el saldo resultante (e indica déficit si dias_disponibles < 0). `RouteContext::urlFor`, admin-only.

## 6. Templates
- `index`: filtros; tabla *Empleado · Inicio · Fin · Días · Acciones*.
- `form`: crear → select de solicitud vacaciones disponible (label "Apellidos, Nombre — dd/mm a dd/mm"); `dias_tomados` (opcional, "vacío = días naturales del rango"). editar → empleado+rango informativos; `dias_tomados` editable.

## 7. Rutas + navbar
Grupo `/vacaciones` admin-only (index `''`, /crear, /{id}/editar, /{id}/eliminar). Navbar: item "Vacaciones" del dropdown *Operaciones* → `url_for('vacaciones.index')`.

## 8. Auditoría INSERT/UPDATE/DELETE sobre `'vacaciones'`.

## 9. Convenciones `url_for`/`base_path`/`RouteContext::urlFor`/modal `data-url`. Sin `path_for`/`base_url`.
