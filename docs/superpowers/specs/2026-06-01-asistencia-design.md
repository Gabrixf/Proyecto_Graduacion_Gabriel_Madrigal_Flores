# Diseño — Módulo Asistencia

- **Proyecto:** Sistema de gestión de nómina — Lubrimotos del Sur (TFG, UIA)
- **Rama:** `feature/asistencia`
- **Fecha:** 2026-06-01
- **Módulo:** #4 — Gestionar Asistencia
- **Patrón de referencia:** Empleados / Feriados (Controller → Service → Repository)

## 1. Alcance

CRUD de registros de asistencia diaria, solo admin. Un registro por empleado por fecha (`UNIQUE(id_empleado, fecha)`). Las horas trabajadas se calculan automáticamente de las marcas de entrada/salida (con override manual). El periodo quincenal y el feriado se resuelven automáticamente a partir de la fecha; solo se permite registrar en periodos en estado `abierto`. Toda escritura se audita.

## 2. Archivos (patrón de 7 pasos)
1. `src/Repositories/AsistenciaRepository.php`
2. `src/Services/AsistenciaService.php`
3. `src/Controllers/AsistenciaController.php`
4. `templates/asistencia/index.html.twig`
5. `templates/asistencia/form.html.twig`
6. `config/dependencies.php` (Repository → Service → Controller)
7. `config/routes.php` (grupo `/asistencia`) + enlace navbar en `templates/layouts/base.html.twig` (dropdown Operaciones)

## 3. Tabla `asistencia` (schema.sql)
`id_asistencia` PK, `id_empleado` FK NOT NULL, `id_periodo` FK NOT NULL, `id_feriado` FK NULL, `fecha` DATE NOT NULL, `hora_entrada` TIME NULL, `hora_salida` TIME NULL, `horas_trabajadas` DECIMAL(4,2) NOT NULL DEFAULT 0.00. `UNIQUE(id_empleado, fecha)`.

## 4. Repository — SQL puro
- `findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array` — SELECT con `JOIN empleados e` (nombre, apellidos) y `LEFT JOIN feriados f` (f.nombre AS feriado_nombre); filtros opcionales por `a.id_periodo` y `a.id_empleado`; `ORDER BY a.fecha DESC, e.apellidos ASC`.
- `findById(int $id): ?array` — registro + nombre del empleado.
- `existsByEmpleadoFecha(int $idEmpleado, string $fecha, ?int $excludeId = null): bool`.
- `findPeriodoAbiertoPorFecha(string $fecha): ?array` — `SELECT id_periodo FROM periodos_pago WHERE :fecha BETWEEN fecha_inicio AND fecha_fin AND estado = 'abierto' LIMIT 1`.
- `findFeriadoIdPorFecha(string $fecha): ?int` — `SELECT id_feriado FROM feriados WHERE fecha = :fecha LIMIT 1`.
- `insert(array $d): int`, `update(int $id, array $d): void`, `delete(int $id): void`.

Las columnas de escritura: `id_empleado, id_periodo, id_feriado, fecha, hora_entrada, hora_salida, horas_trabajadas`.

## 5. Service — lógica y validación
Deps: `AsistenciaRepository $repo`, `EmpleadosRepository $empleadosRepo` (reuso para el dropdown de empleados activos), `AuditoriaRepository $auditoriaRepo`.

Métodos: `listar(?int,?int)`, `obtener(int)`, `datosFormulario()` (→ `['empleados' => empleadosRepo->findAll('activo'), 'periodos' => ...]` para los filtros del index el controller usa periodos), `crear(array,int,string):int`, `actualizar(int,array,int,string):void`, `eliminar(int,int,string):void`.

> Para el filtro por periodo en el index se reutiliza `PeriodosRepository::findAll()`. Por simplicidad, `datosFormulario()` devuelve solo `empleados` (lo que necesita el form); el index obtiene periodos vía un método aparte `listarPeriodos()` que delega en `PeriodosRepository`.

**Validación / lógica de `crear`/`actualizar` (validar devuelve array de columnas listas):**
- `id_empleado`: entero > 0, debe existir y estar `activo` (`empleadosRepo->findById`; estado === 'activo').
- `fecha`: formato `Y-m-d` válido; no puede ser futura (> hoy).
- `hora_entrada`, `hora_salida`: formato `H:i` válido; `salida > entrada` (misma jornada, sin nocturno). Ambas obligatorias.
- `horas_trabajadas`: si el admin envía un valor numérico > 0 se usa como **override**; si no, se calcula = `(salida − entrada)` en horas decimales, redondeado a 2. Debe quedar > 0 y ≤ 24.
- **Periodo:** `findPeriodoAbiertoPorFecha(fecha)`; si null → `InvalidArgumentException('No hay un periodo de pago abierto que contenga esa fecha.')`. El `id_periodo` resultante se guarda.
- **Feriado:** `findFeriadoIdPorFecha(fecha)` → `id_feriado` (nullable).
- Guard duplicado: `existsByEmpleadoFecha(idEmpleado, fecha, excludeId)` → error "Ya existe un registro de asistencia para ese empleado en esa fecha."
- Errores acumulados y lanzados juntos como `InvalidArgumentException`. Violación de integridad `23xxx` traducida; otras `PDOException` se relanzan.

## 6. Controller — solo HTTP
`index` (filtros `?periodo=`, `?empleado=` → pasa a `listar`; provee listas de periodos y empleados para los selects de filtro), `create`, `store`, `edit`, `update`, `destroy`. Redirects vía helper `urlFor(Request,$name)` con `RouteContext`. Lee `$_SESSION['usuario_id']` + IP. 422 re-render con datos previos. Solo admin.

## 7. Templates
- **index:** barra de filtros (select periodo + select empleado + botón Filtrar). Tabla: *Empleado · Fecha · Entrada · Salida · Horas · Feriado (badge si aplica) · Acciones (Editar / Eliminar con modal)*.
- **form:** select empleado (activos), fecha, hora_entrada, hora_salida, horas_trabajadas (opcional, "dejar vacío para calcular automáticamente"). Periodo y feriado **no** se muestran (auto al guardar). Reusa `asistencia.*` para repoblar tras 422.

## 8. Rutas + navbar
Grupo `/asistencia` envuelto en `RoleMiddleware('admin')` + `AuthMiddleware`, patrón index con string vacío `''` (→ `/asistencia` sin barra final):
```
''               → index   (asistencia.index)
/crear           → create/store
/{id}/editar     → edit/update
/{id}/eliminar   → destroy
```
En `base.html.twig`, el item "Asistencia" del dropdown *Operaciones* pasa de `href="#"` a `url_for('asistencia.index')`.

## 9. Auditoría
`INSERT`/`UPDATE`/`DELETE` sobre `'asistencia'` vía `AuditoriaRepository::insert(...)`.

## 10. Decisiones acordadas
| Decisión | Elección |
|---|---|
| horas_trabajadas | Auto = salida − entrada, con override manual opcional |
| Periodo y feriado | Automáticos desde la fecha; solo periodos `abierto` |
| Captura | CRUD por registro, index filtrable por periodo y empleado |

## 11. Convenciones heredadas (slim/twig-view 3.4)
Plantillas usan `url_for(...)` y `base_path()`; controller usa `RouteContext::fromRequest($request)->getRouteParser()->urlFor($name)`. NO `path_for`/`base_url`/`Location` hardcodeado.
