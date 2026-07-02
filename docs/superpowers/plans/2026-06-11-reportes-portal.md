# Reportes y Portal del Empleado (Módulo 13) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the last module: 4 admin read-only reports under `/reportes` (planilla, historial, costos patronales, auditoría) plus the employee self-service portal (`/mi-perfil`, `/mis-colillas`, `/mis-vacaciones`) with a print-friendly view.

**Architecture:** Two Controller → Service → Repository trios over raw PDO, both read-only (no writes, no audit entries). Report filters travel via GET query params, validated in the Services (invalid filters are ignored, never 500). Portal queries always filter by the session's `id_usuario` inside the SQL itself.

**Tech Stack:** PHP 8.1 / Slim 4 / Twig 3 / PHP-DI 7 / PDO MySQL. No new dependencies; printing = `@media print` CSS + `window.print()`.

**Spec:** `docs/superpowers/specs/2026-06-11-reportes-portal-design.md`

**Branch:** `feature/reportes` (already created). All commits go here, never to `main`.

**Conventions reminders (from CLAUDE.md):**
- Every PHP file starts with `declare(strict_types=1)`.
- Repositories: SQL only. Services: business rules. Controllers: HTTP only.
- Read-only module → no `AuditoriaRepository` calls anywhere.
- Run tests with `vendor\bin\phpunit` (Windows). Syntax-check files with `php -l <file>`.
- Key data facts: `nominas.total_deducciones` and `nominas.salario_neto` already EXCLUDE the employer's `CCSS_patronal` line (it is informative-only); `deducciones_nomina.tipo = 'CCSS_patronal'` holds the employer cost.

---

### Task 1: ReportesRepository

**Files:**
- Create: `src/Repositories/ReportesRepository.php`

- [ ] **Step 1: Write the repository**

Create `src/Repositories/ReportesRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * ReportesRepository — SQL de solo lectura para los reportes administrativos.
 * Sin escrituras; los agregados (SUM, GROUP BY) viven aquí, no en el Service.
 */
class ReportesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Catálogos para los filtros ────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->pdo->query(
            'SELECT id_periodo, fecha_inicio, fecha_fin, estado
               FROM periodos_pago ORDER BY fecha_inicio DESC'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function empleados(): array
    {
        return $this->pdo->query(
            'SELECT id_empleado, nombre, apellidos FROM empleados ORDER BY apellidos'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function usuarios(): array
    {
        return $this->pdo->query(
            'SELECT id_usuario, nombre_usuario FROM usuarios ORDER BY nombre_usuario'
        )->fetchAll();
    }

    // ── Planilla por período ──────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function planillaPorPeriodo(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id_nomina, n.estado, n.salario_bruto, n.total_deducciones, n.salario_neto,
                    e.nombre, e.apellidos, e.cedula
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
              WHERE n.id_periodo = :p
              ORDER BY e.apellidos'
        );
        $stmt->execute([':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    // ── Historial por empleado (rango de fechas) ──────────

    /** @return array<int, array<string, mixed>> */
    public function nominasEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id_nomina, n.salario_bruto, n.total_deducciones, n.salario_neto, n.estado,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_empleado = :e AND p.fecha_fin >= :d AND p.fecha_inicio <= :h
              ORDER BY p.fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function horasExtraEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha, cantidad_horas, factor_recargo
               FROM horas_extra
              WHERE id_empleado = :e AND fecha BETWEEN :d AND :h
              ORDER BY fecha DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function vacacionesEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, dias_tomados
               FROM vacaciones
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function incapacidadesEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, dias, tipo
               FROM incapacidades
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function permisosEmpleado(int $idEmpleado, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_inicio, fecha_fin, con_goce_salarial
               FROM permisos
              WHERE id_empleado = :e AND fecha_fin >= :d AND fecha_inicio <= :h
              ORDER BY fecha_inicio DESC'
        );
        $stmt->execute([':e' => $idEmpleado, ':d' => $desde, ':h' => $hasta]);
        return $stmt->fetchAll();
    }

    // ── Costos patronales por período ─────────────────────

    /** @return array<int, array<string, mixed>> */
    public function costosPorPeriodo(int $idPeriodo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.nombre, e.apellidos, n.salario_bruto,
                    COALESCE(SUM(CASE WHEN d.tipo = 'CCSS_patronal' THEN d.monto END), 0) AS ccss_patronal,
                    ROUND(n.salario_bruto / 12, 2) AS provision_aguinaldo
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               LEFT JOIN deducciones_nomina d ON d.id_nomina = n.id_nomina
              WHERE n.id_periodo = :p
              GROUP BY n.id_nomina, e.nombre, e.apellidos, n.salario_bruto
              ORDER BY e.apellidos"
        );
        $stmt->execute([':p' => $idPeriodo]);
        return $stmt->fetchAll();
    }

    // ── Bitácora de auditoría ─────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function auditoria(?string $accion, ?int $idUsuario, ?string $desde, ?string $hasta, int $limite): array
    {
        $sql = 'SELECT a.id_auditoria, a.fecha_accion, a.accion, a.tabla_afectada,
                       a.id_registro, a.ip_origen, a.detalle, u.nombre_usuario
                  FROM auditoria a
                  JOIN usuarios u ON u.id_usuario = a.id_usuario
                 WHERE 1 = 1';
        $params = [];
        if ($accion !== null) {
            $sql .= ' AND a.accion = :a';
            $params[':a'] = $accion;
        }
        if ($idUsuario !== null) {
            $sql .= ' AND a.id_usuario = :u';
            $params[':u'] = $idUsuario;
        }
        if ($desde !== null) {
            $sql .= ' AND a.fecha_accion >= :d';
            $params[':d'] = $desde . ' 00:00:00';
        }
        if ($hasta !== null) {
            $sql .= ' AND a.fecha_accion <= :h';
            $params[':h'] = $hasta . ' 23:59:59';
        }
        $sql .= ' ORDER BY a.fecha_accion DESC LIMIT ' . $limite;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Repositories/ReportesRepository.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/ReportesRepository.php
git commit -m "feat(reportes): add ReportesRepository"
```

---

### Task 2: ReportesService

**Files:**
- Create: `src/Services/ReportesService.php`

- [ ] **Step 1: Write the service**

Create `src/Services/ReportesService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ReportesRepository;
use DateTime;

/**
 * ReportesService — valida los filtros GET y arma las estructuras para Twig.
 * Filtros inválidos se ignoran (reporte sin selección); nunca lanzan excepción.
 */
class ReportesService
{
    private const ACCIONES_AUDITORIA = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT'];
    private const LIMITE_AUDITORIA = 200;

    public function __construct(private readonly ReportesRepository $repo) {}

    /** @return array<string, mixed> */
    public function planilla(array $filtros): array
    {
        $idPeriodo = $this->idValido($filtros['periodo'] ?? null);
        $filas = $idPeriodo !== null ? $this->repo->planillaPorPeriodo($idPeriodo) : [];
        $totales = ['bruto' => 0.0, 'deducciones' => 0.0, 'neto' => 0.0];
        foreach ($filas as $f) {
            $totales['bruto']       += (float) $f['salario_bruto'];
            $totales['deducciones'] += (float) $f['total_deducciones'];
            $totales['neto']        += (float) $f['salario_neto'];
        }
        return [
            'periodos'  => $this->repo->periodos(),
            'idPeriodo' => $idPeriodo,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    /** @return array<string, mixed> */
    public function historial(array $filtros): array
    {
        $idEmpleado = $this->idValido($filtros['empleado'] ?? null);
        [$desde, $hasta] = $this->rangoFechas(
            $filtros['desde'] ?? null,
            $filtros['hasta'] ?? null,
            porDefectoAnio: true
        );
        $secciones = null;
        if ($idEmpleado !== null) {
            $secciones = [
                'nominas'       => $this->repo->nominasEmpleado($idEmpleado, $desde, $hasta),
                'horas_extra'   => $this->repo->horasExtraEmpleado($idEmpleado, $desde, $hasta),
                'vacaciones'    => $this->repo->vacacionesEmpleado($idEmpleado, $desde, $hasta),
                'incapacidades' => $this->repo->incapacidadesEmpleado($idEmpleado, $desde, $hasta),
                'permisos'      => $this->repo->permisosEmpleado($idEmpleado, $desde, $hasta),
            ];
        }
        return [
            'empleados'  => $this->repo->empleados(),
            'idEmpleado' => $idEmpleado,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'secciones'  => $secciones,
        ];
    }

    /** @return array<string, mixed> */
    public function costos(array $filtros): array
    {
        $idPeriodo = $this->idValido($filtros['periodo'] ?? null);
        $filas = [];
        $totales = ['bruto' => 0.0, 'ccss' => 0.0, 'aguinaldo' => 0.0, 'total' => 0.0];
        if ($idPeriodo !== null) {
            foreach ($this->repo->costosPorPeriodo($idPeriodo) as $f) {
                $f['costo_total'] = round(
                    (float) $f['salario_bruto'] + (float) $f['ccss_patronal'] + (float) $f['provision_aguinaldo'],
                    2
                );
                $totales['bruto']     += (float) $f['salario_bruto'];
                $totales['ccss']      += (float) $f['ccss_patronal'];
                $totales['aguinaldo'] += (float) $f['provision_aguinaldo'];
                $totales['total']     += $f['costo_total'];
                $filas[] = $f;
            }
        }
        return [
            'periodos'  => $this->repo->periodos(),
            'idPeriodo' => $idPeriodo,
            'filas'     => $filas,
            'totales'   => $totales,
        ];
    }

    /** @return array<string, mixed> */
    public function auditoria(array $filtros): array
    {
        $accion = in_array($filtros['accion'] ?? '', self::ACCIONES_AUDITORIA, true)
            ? (string) $filtros['accion'] : null;
        $idUsuario = $this->idValido($filtros['usuario'] ?? null);
        [$desde, $hasta] = $this->rangoFechas(
            $filtros['desde'] ?? null,
            $filtros['hasta'] ?? null,
            porDefectoAnio: false
        );
        return [
            'usuarios'  => $this->repo->usuarios(),
            'acciones'  => self::ACCIONES_AUDITORIA,
            'accion'    => $accion,
            'idUsuario' => $idUsuario,
            'desde'     => $desde,
            'hasta'     => $hasta,
            'limite'    => self::LIMITE_AUDITORIA,
            'registros' => $this->repo->auditoria($accion, $idUsuario, $desde, $hasta, self::LIMITE_AUDITORIA),
        ];
    }

    private function idValido(mixed $valor): ?int
    {
        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * Devuelve [desde, hasta] saneados. Con porDefectoAnio, los vacíos se llenan
     * con el año actual; si desde > hasta se intercambian.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function rangoFechas(?string $desde, ?string $hasta, bool $porDefectoAnio): array
    {
        $desde = $this->fechaValida($desde);
        $hasta = $this->fechaValida($hasta);
        if ($porDefectoAnio) {
            $desde ??= date('Y') . '-01-01';
            $hasta ??= date('Y') . '-12-31';
        }
        if ($desde !== null && $hasta !== null && $desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        return [$desde, $hasta];
    }

    private function fechaValida(?string $fecha): ?string
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $fecha);
        return $d !== false && $d->format('Y-m-d') === $fecha ? $fecha : null;
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Services/ReportesService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Services/ReportesService.php
git commit -m "feat(reportes): add ReportesService with filter validation"
```

---

### Task 3: ReportesController

**Files:**
- Create: `src/Controllers/ReportesController.php`

- [ ] **Step 1: Write the controller**

Create `src/Controllers/ReportesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReportesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ReportesController
{
    public function __construct(
        private readonly Twig            $twig,
        private readonly ReportesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'reportes/index.html.twig', [
            'titulo' => 'Reportes',
        ]);
    }

    public function planilla(Request $request, Response $response): Response
    {
        $datos = $this->service->planilla($request->getQueryParams());
        return $this->twig->render($response, 'reportes/planilla.html.twig', $datos + [
            'titulo' => 'Planilla por Período',
        ]);
    }

    public function historial(Request $request, Response $response): Response
    {
        $datos = $this->service->historial($request->getQueryParams());
        return $this->twig->render($response, 'reportes/historial.html.twig', $datos + [
            'titulo' => 'Historial por Empleado',
        ]);
    }

    public function costos(Request $request, Response $response): Response
    {
        $datos = $this->service->costos($request->getQueryParams());
        return $this->twig->render($response, 'reportes/costos.html.twig', $datos + [
            'titulo' => 'Costos Patronales',
        ]);
    }

    public function auditoria(Request $request, Response $response): Response
    {
        $datos = $this->service->auditoria($request->getQueryParams());
        return $this->twig->render($response, 'reportes/auditoria.html.twig', $datos + [
            'titulo' => 'Bitácora de Auditoría',
        ]);
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Controllers/ReportesController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/ReportesController.php
git commit -m "feat(reportes): add ReportesController"
```

---

### Task 4: Reportes templates

**Files:**
- Create: `templates/reportes/index.html.twig`
- Create: `templates/reportes/planilla.html.twig`
- Create: `templates/reportes/historial.html.twig`
- Create: `templates/reportes/costos.html.twig`
- Create: `templates/reportes/auditoria.html.twig`

- [ ] **Step 1: Hub (index)**

Create `templates/reportes/index.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Reportes{% endblock %}
{% block breadcrumb %}Inicio / Reportes{% endblock %}

{% block contenido %}
<div class="row g-3" style="max-width:960px">
    {% set tarjetas = [
        {ruta: 'reportes.planilla',  icono: 'bi-table',          nombre: 'Planilla por Período',
         desc:  'Bruto, deducciones y neto de cada empleado en un período de pago.'},
        {ruta: 'reportes.historial', icono: 'bi-person-lines-fill', nombre: 'Historial por Empleado',
         desc:  'Nóminas, horas extra, vacaciones, incapacidades y permisos en un rango de fechas.'},
        {ruta: 'reportes.costos',    icono: 'bi-cash-stack',     nombre: 'Costos Patronales',
         desc:  'CCSS patronal y provisión de aguinaldo: el costo real de cada empleado.'},
        {ruta: 'reportes.auditoria', icono: 'bi-shield-check',   nombre: 'Bitácora de Auditoría',
         desc:  'Trazabilidad de operaciones y sesiones del sistema (Ley 8968).'}
    ] %}
    {% for t in tarjetas %}
    <div class="col-sm-6">
        <a href="{{ url_for(t.ruta) }}" class="card-fo d-block h-100 text-decoration-none">
            <i class="bi {{ t.icono }} fs-2 d-block mb-2"></i>
            <div class="fw-bold mb-1">{{ t.nombre }}</div>
            <div class="text-muted small">{{ t.desc }}</div>
        </a>
    </div>
    {% endfor %}
</div>
{% endblock %}
```

- [ ] **Step 2: Planilla**

Create `templates/reportes/planilla.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Planilla por Período{% endblock %}
{% block breadcrumb %}Inicio / Reportes / Planilla{% endblock %}

{% block contenido %}
<form method="GET" class="card-fo mb-3 no-print" style="max-width:960px">
    <div class="row g-2 align-items-end">
        <div class="col-sm-6">
            <label class="form-label fw-semibold">Período de pago</label>
            <select name="periodo" class="form-select">
                <option value="">— Seleccione —</option>
                {% for p in periodos %}
                <option value="{{ p.id_periodo }}" {{ idPeriodo == p.id_periodo ? 'selected' : '' }}>
                    {{ p.fecha_inicio }} — {{ p.fecha_fin }} ({{ p.estado }})
                </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Consultar</button>
        </div>
        {% if filas %}
        <div class="col-auto">
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
        </div>
        {% endif %}
    </div>
</form>

<div class="card-fo" style="max-width:960px">
    <table class="table align-middle">
        <thead>
            <tr><th>Empleado</th><th>Cédula</th><th>Estado</th>
                <th class="text-end">Salario bruto</th>
                <th class="text-end">Deducciones</th>
                <th class="text-end">Salario neto</th></tr>
        </thead>
        <tbody>
            {% for f in filas %}
            <tr>
                <td class="fw-semibold">{{ f.nombre }} {{ f.apellidos }}</td>
                <td>{{ f.cedula }}</td>
                <td><span class="badge text-bg-secondary">{{ f.estado }}</span></td>
                <td class="text-end">{{ f.salario_bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ f.total_deducciones|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ f.salario_neto|number_format(2, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="6" class="text-center text-muted py-4">
                {{ idPeriodo ? 'No hay nóminas registradas en el período seleccionado.'
                             : 'Seleccione un período para generar la planilla.' }}
            </td></tr>
            {% endfor %}
        </tbody>
        {% if filas %}
        <tfoot>
            <tr class="fw-bold">
                <td colspan="3">Totales</td>
                <td class="text-end">{{ totales.bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ totales.deducciones|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ totales.neto|number_format(2, '.', ',') }}</td>
            </tr>
        </tfoot>
        {% endif %}
    </table>
</div>
{% endblock %}
```

- [ ] **Step 3: Historial**

Create `templates/reportes/historial.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Historial por Empleado{% endblock %}
{% block breadcrumb %}Inicio / Reportes / Historial{% endblock %}

{% block contenido %}
<form method="GET" class="card-fo mb-3 no-print" style="max-width:960px">
    <div class="row g-2 align-items-end">
        <div class="col-sm-4">
            <label class="form-label fw-semibold">Empleado</label>
            <select name="empleado" class="form-select">
                <option value="">— Seleccione —</option>
                {% for e in empleados %}
                <option value="{{ e.id_empleado }}" {{ idEmpleado == e.id_empleado ? 'selected' : '' }}>
                    {{ e.nombre }} {{ e.apellidos }}
                </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-semibold">Desde</label>
            <input type="date" name="desde" value="{{ desde }}" class="form-control">
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-semibold">Hasta</label>
            <input type="date" name="hasta" value="{{ hasta }}" class="form-control">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Consultar</button>
        </div>
        {% if secciones %}
        <div class="col-auto">
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
        </div>
        {% endif %}
    </div>
</form>

{% if secciones is null %}
<div class="card-fo text-center text-muted py-5" style="max-width:960px">
    Seleccione un empleado para consultar su historial.
</div>
{% else %}

<div class="card-fo mb-3" style="max-width:960px">
    <h6 class="fw-bold mb-2"><i class="bi bi-receipt me-1"></i>Nóminas</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Período</th><th>Estado</th><th class="text-end">Bruto</th>
            <th class="text-end">Deducciones</th><th class="text-end">Neto</th></tr></thead>
        <tbody>
            {% for n in secciones.nominas %}
            <tr>
                <td>{{ n.fecha_inicio }} — {{ n.fecha_fin }}</td>
                <td><span class="badge text-bg-secondary">{{ n.estado }}</span></td>
                <td class="text-end">{{ n.salario_bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ n.total_deducciones|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ n.salario_neto|number_format(2, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="5" class="text-center text-muted">Sin registros en el rango.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="card-fo mb-3" style="max-width:960px">
    <h6 class="fw-bold mb-2"><i class="bi bi-alarm me-1"></i>Horas extra</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Fecha</th><th class="text-end">Horas</th><th class="text-end">Factor</th></tr></thead>
        <tbody>
            {% for h in secciones.horas_extra %}
            <tr>
                <td>{{ h.fecha }}</td>
                <td class="text-end">{{ h.cantidad_horas|number_format(2, '.', ',') }}</td>
                <td class="text-end">× {{ h.factor_recargo|number_format(2, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="3" class="text-center text-muted">Sin registros en el rango.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="card-fo mb-3" style="max-width:960px">
    <h6 class="fw-bold mb-2"><i class="bi bi-sun me-1"></i>Vacaciones</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Desde</th><th>Hasta</th><th class="text-end">Días</th></tr></thead>
        <tbody>
            {% for v in secciones.vacaciones %}
            <tr>
                <td>{{ v.fecha_inicio }}</td>
                <td>{{ v.fecha_fin }}</td>
                <td class="text-end">{{ v.dias_tomados|number_format(1, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="3" class="text-center text-muted">Sin registros en el rango.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="card-fo mb-3" style="max-width:960px">
    <h6 class="fw-bold mb-2"><i class="bi bi-bandaid me-1"></i>Incapacidades</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Desde</th><th>Hasta</th><th class="text-end">Días</th><th>Tipo</th></tr></thead>
        <tbody>
            {% for i in secciones.incapacidades %}
            <tr>
                <td>{{ i.fecha_inicio }}</td>
                <td>{{ i.fecha_fin }}</td>
                <td class="text-end">{{ i.dias }}</td>
                <td>{{ i.tipo }}</td>
            </tr>
            {% else %}
            <tr><td colspan="4" class="text-center text-muted">Sin registros en el rango.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="card-fo mb-3" style="max-width:960px">
    <h6 class="fw-bold mb-2"><i class="bi bi-door-open me-1"></i>Permisos</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Desde</th><th>Hasta</th><th>Goce salarial</th></tr></thead>
        <tbody>
            {% for p in secciones.permisos %}
            <tr>
                <td>{{ p.fecha_inicio }}</td>
                <td>{{ p.fecha_fin }}</td>
                <td>{{ p.con_goce_salarial ? 'Con goce' : 'Sin goce' }}</td>
            </tr>
            {% else %}
            <tr><td colspan="3" class="text-center text-muted">Sin registros en el rango.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

{% endif %}
{% endblock %}
```

- [ ] **Step 4: Costos patronales**

Create `templates/reportes/costos.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Costos Patronales{% endblock %}
{% block breadcrumb %}Inicio / Reportes / Costos Patronales{% endblock %}

{% block contenido %}
<form method="GET" class="card-fo mb-3 no-print" style="max-width:960px">
    <div class="row g-2 align-items-end">
        <div class="col-sm-6">
            <label class="form-label fw-semibold">Período de pago</label>
            <select name="periodo" class="form-select">
                <option value="">— Seleccione —</option>
                {% for p in periodos %}
                <option value="{{ p.id_periodo }}" {{ idPeriodo == p.id_periodo ? 'selected' : '' }}>
                    {{ p.fecha_inicio }} — {{ p.fecha_fin }} ({{ p.estado }})
                </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Consultar</button>
        </div>
        {% if filas %}
        <div class="col-auto">
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
        </div>
        {% endif %}
    </div>
</form>

<div class="card-fo" style="max-width:960px">
    <table class="table align-middle">
        <thead>
            <tr><th>Empleado</th>
                <th class="text-end">Salario bruto</th>
                <th class="text-end">CCSS patronal</th>
                <th class="text-end">Provisión aguinaldo</th>
                <th class="text-end">Costo total</th></tr>
        </thead>
        <tbody>
            {% for f in filas %}
            <tr>
                <td class="fw-semibold">{{ f.nombre }} {{ f.apellidos }}</td>
                <td class="text-end">{{ f.salario_bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ f.ccss_patronal|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ f.provision_aguinaldo|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ f.costo_total|number_format(2, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="5" class="text-center text-muted py-4">
                {{ idPeriodo ? 'No hay nóminas registradas en el período seleccionado.'
                             : 'Seleccione un período para calcular los costos.' }}
            </td></tr>
            {% endfor %}
        </tbody>
        {% if filas %}
        <tfoot>
            <tr class="fw-bold">
                <td>Totales</td>
                <td class="text-end">{{ totales.bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ totales.ccss|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ totales.aguinaldo|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ totales.total|number_format(2, '.', ',') }}</td>
            </tr>
        </tfoot>
        {% endif %}
    </table>
</div>
{% endblock %}
```

- [ ] **Step 5: Auditoría**

Create `templates/reportes/auditoria.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Bitácora de Auditoría{% endblock %}
{% block breadcrumb %}Inicio / Reportes / Auditoría{% endblock %}

{% block contenido %}
<form method="GET" class="card-fo mb-3 no-print">
    <div class="row g-2 align-items-end">
        <div class="col-sm-2">
            <label class="form-label fw-semibold">Acción</label>
            <select name="accion" class="form-select">
                <option value="">Todas</option>
                {% for a in acciones %}
                <option value="{{ a }}" {{ accion == a ? 'selected' : '' }}>{{ a }}</option>
                {% endfor %}
            </select>
        </div>
        <div class="col-sm-3">
            <label class="form-label fw-semibold">Usuario</label>
            <select name="usuario" class="form-select">
                <option value="">Todos</option>
                {% for u in usuarios %}
                <option value="{{ u.id_usuario }}" {{ idUsuario == u.id_usuario ? 'selected' : '' }}>
                    {{ u.nombre_usuario }}
                </option>
                {% endfor %}
            </select>
        </div>
        <div class="col-sm-2">
            <label class="form-label fw-semibold">Desde</label>
            <input type="date" name="desde" value="{{ desde }}" class="form-control">
        </div>
        <div class="col-sm-2">
            <label class="form-label fw-semibold">Hasta</label>
            <input type="date" name="hasta" value="{{ hasta }}" class="form-control">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary"><i class="bi bi-search me-1"></i>Filtrar</button>
        </div>
        <div class="col-auto">
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Imprimir
            </button>
        </div>
    </div>
</form>

<div class="card-fo">
    <div class="text-muted small mb-2">Se muestran los últimos {{ limite }} registros que cumplen los filtros.</div>
    <table class="table table-sm align-middle">
        <thead>
            <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Tabla</th>
                <th class="text-end">Registro</th><th>IP</th><th>Detalle</th></tr>
        </thead>
        <tbody>
            {% for r in registros %}
            <tr>
                <td class="text-nowrap">{{ r.fecha_accion }}</td>
                <td>{{ r.nombre_usuario }}</td>
                <td><span class="badge text-bg-secondary">{{ r.accion }}</span></td>
                <td>{{ r.tabla_afectada }}</td>
                <td class="text-end">{{ r.id_registro }}</td>
                <td>{{ r.ip_origen }}</td>
                <td class="text-muted small">{{ r.detalle }}</td>
            </tr>
            {% else %}
            <tr><td colspan="7" class="text-center text-muted py-4">Sin registros que cumplan los filtros.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

- [ ] **Step 6: Commit**

```bash
git add templates/reportes/
git commit -m "feat(reportes): add hub, planilla, historial, costos and auditoria templates"
```

---

### Task 5: PortalRepository

**Files:**
- Create: `src/Repositories/PortalRepository.php`

- [ ] **Step 1: Write the repository**

Create `src/Repositories/PortalRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * PortalRepository — consultas del empleado autenticado.
 * Toda consulta filtra por empleados.id_usuario en el propio SQL:
 * un empleado nunca puede leer datos de otro aunque manipule IDs.
 */
class PortalRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function idEmpleadoPorUsuario(int $idUsuario): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_empleado FROM empleados WHERE id_usuario = :u LIMIT 1'
        );
        $stmt->execute([':u' => $idUsuario]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Perfil del empleado vinculado al usuario. @return array<string, mixed>|null */
    public function perfil(int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, p.nombre AS puesto, p.salario_base AS salario_puesto
               FROM empleados e
               JOIN puestos p ON p.id_puesto = e.id_puesto
              WHERE e.id_usuario = :u LIMIT 1'
        );
        $stmt->execute([':u' => $idUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** Cuentas bancarias activas del empleado. @return array<int, array<string, mixed>> */
    public function datosBancarios(int $idEmpleado): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT banco, tipo_cuenta, numero_cuenta, numero_cuenta_iban, moneda
               FROM datos_bancarios
              WHERE id_empleado = :e AND activa = 1
              ORDER BY id_datos_bancarios'
        );
        $stmt->execute([':e' => $idEmpleado]);
        return $stmt->fetchAll();
    }

    /** Nóminas visibles como colilla (aprobado/pagado). @return array<int, array<string, mixed>> */
    public function colillas(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.id_nomina, n.salario_bruto, n.total_deducciones, n.salario_neto, n.estado,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE e.id_usuario = :u AND n.estado IN ('aprobado', 'pagado')
              ORDER BY p.fecha_inicio DESC"
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    /** Cabecera de una colilla propia; null si el id no pertenece al usuario. @return array<string, mixed>|null */
    public function colilla(int $idNomina, int $idUsuario): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT n.*, e.nombre, e.apellidos, e.cedula, pu.nombre AS puesto,
                    p.fecha_inicio, p.fecha_fin
               FROM nominas n
               JOIN empleados e ON e.id_empleado = n.id_empleado
               JOIN puestos pu ON pu.id_puesto = e.id_puesto
               JOIN periodos_pago p ON p.id_periodo = n.id_periodo
              WHERE n.id_nomina = :id AND e.id_usuario = :u
                AND n.estado IN ('aprobado', 'pagado')
              LIMIT 1"
        );
        $stmt->execute([':id' => $idNomina, ':u' => $idUsuario]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function ingresosColilla(int $idNomina): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tipo, descripcion, monto FROM ingresos_nomina
              WHERE id_nomina = :id ORDER BY id_ingreso'
        );
        $stmt->execute([':id' => $idNomina]);
        return $stmt->fetchAll();
    }

    /** Deducciones del empleado (excluye la CCSS patronal, que es informativa). @return array<int, array<string, mixed>> */
    public function deduccionesColilla(int $idNomina): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tipo, descripcion, porcentaje, monto FROM deducciones_nomina
              WHERE id_nomina = :id AND tipo <> 'CCSS_patronal' ORDER BY id_deduccion"
        );
        $stmt->execute([':id' => $idNomina]);
        return $stmt->fetchAll();
    }

    /** Saldo de vacaciones por año. @return array<int, array<string, mixed>> */
    public function saldosVacaciones(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.anio, s.dias_ganados, s.dias_disfrutados, s.dias_disponibles
               FROM saldo_vacaciones s
               JOIN empleados e ON e.id_empleado = s.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY s.anio DESC'
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }

    /** Vacaciones disfrutadas. @return array<int, array<string, mixed>> */
    public function vacacionesDisfrutadas(int $idUsuario): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.fecha_inicio, v.fecha_fin, v.dias_tomados
               FROM vacaciones v
               JOIN empleados e ON e.id_empleado = v.id_empleado
              WHERE e.id_usuario = :u
              ORDER BY v.fecha_inicio DESC'
        );
        $stmt->execute([':u' => $idUsuario]);
        return $stmt->fetchAll();
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Repositories/PortalRepository.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Repositories/PortalRepository.php
git commit -m "feat(portal): add PortalRepository with session-scoped queries"
```

---

### Task 6: PortalService

**Files:**
- Create: `src/Services/PortalService.php`

- [ ] **Step 1: Write the service**

Create `src/Services/PortalService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PortalRepository;
use RuntimeException;

/**
 * PortalService — autoconsulta del empleado autenticado (solo lectura).
 * Si el usuario no tiene empleado vinculado, las vistas reciben vinculado=false.
 */
class PortalService
{
    public function __construct(private readonly PortalRepository $repo) {}

    /** @return array{vinculado: bool, empleado: ?array<string, mixed>, bancos: array<int, array<string, mixed>>} */
    public function perfil(int $idUsuario): array
    {
        $empleado = $this->repo->perfil($idUsuario);
        return [
            'vinculado' => $empleado !== null,
            'empleado'  => $empleado,
            'bancos'    => $empleado !== null
                ? $this->repo->datosBancarios((int) $empleado['id_empleado'])
                : [],
        ];
    }

    /** @return array{vinculado: bool, colillas: array<int, array<string, mixed>>} */
    public function colillas(int $idUsuario): array
    {
        $vinculado = $this->repo->idEmpleadoPorUsuario($idUsuario) !== null;
        return [
            'vinculado' => $vinculado,
            'colillas'  => $vinculado ? $this->repo->colillas($idUsuario) : [],
        ];
    }

    /** Colilla propia con líneas; lanza RuntimeException si no existe o es ajena. @return array<string, mixed> */
    public function colilla(int $idNomina, int $idUsuario): array
    {
        $cab = $this->repo->colilla($idNomina, $idUsuario);
        if ($cab === null) {
            throw new RuntimeException('Colilla no encontrada.');
        }
        $cab['ingresos']    = $this->repo->ingresosColilla($idNomina);
        $cab['deducciones'] = $this->repo->deduccionesColilla($idNomina);
        return $cab;
    }

    /** @return array{vinculado: bool, saldos: array<int, array<string, mixed>>, historial: array<int, array<string, mixed>>} */
    public function vacaciones(int $idUsuario): array
    {
        $vinculado = $this->repo->idEmpleadoPorUsuario($idUsuario) !== null;
        return [
            'vinculado' => $vinculado,
            'saldos'    => $vinculado ? $this->repo->saldosVacaciones($idUsuario) : [],
            'historial' => $vinculado ? $this->repo->vacacionesDisfrutadas($idUsuario) : [],
        ];
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Services/PortalService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Services/PortalService.php
git commit -m "feat(portal): add PortalService"
```

---

### Task 7: PortalController

**Files:**
- Create: `src/Controllers/PortalController.php`

- [ ] **Step 1: Write the controller**

Create `src/Controllers/PortalController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class PortalController
{
    public function __construct(
        private readonly Twig          $twig,
        private readonly PortalService $service
    ) {}

    public function perfil(Request $request, Response $response): Response
    {
        $datos = $this->service->perfil((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mi_perfil.html.twig', $datos + [
            'titulo' => 'Mi Perfil',
        ]);
    }

    public function colillas(Request $request, Response $response): Response
    {
        $datos = $this->service->colillas((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_colillas.html.twig', $datos + [
            'titulo'     => 'Mis Colillas',
            'flashError' => $this->consumeFlash('flash_error'),
        ]);
    }

    public function colilla(Request $request, Response $response, array $args): Response
    {
        try {
            $colilla = $this->service->colilla((int) $args['id'], (int) $_SESSION['usuario_id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Colilla no encontrada.';
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('portal.colillas');
            return $response->withHeader('Location', $url)->withStatus(302);
        }
        return $this->twig->render($response, 'portal/colilla.html.twig', [
            'titulo'  => 'Colilla de Pago',
            'colilla' => $colilla,
        ]);
    }

    public function vacaciones(Request $request, Response $response): Response
    {
        $datos = $this->service->vacaciones((int) $_SESSION['usuario_id']);
        return $this->twig->render($response, 'portal/mis_vacaciones.html.twig', $datos + [
            'titulo' => 'Mis Vacaciones',
        ]);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l src/Controllers/PortalController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/PortalController.php
git commit -m "feat(portal): add PortalController"
```

---

### Task 8: Portal templates

**Files:**
- Create: `templates/portal/mi_perfil.html.twig`
- Create: `templates/portal/mis_colillas.html.twig`
- Create: `templates/portal/colilla.html.twig`
- Create: `templates/portal/mis_vacaciones.html.twig`

Shared empty-state rule: when `vinculado == false`, every portal view shows the same friendly card (see mi_perfil below) instead of its content.

- [ ] **Step 1: Mi Perfil**

Create `templates/portal/mi_perfil.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Mi Perfil{% endblock %}
{% block breadcrumb %}Inicio / Mi Perfil{% endblock %}

{% block contenido %}
{% if not vinculado %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
    Su usuario no está vinculado a un empleado.
</div>
{% else %}
<div class="card-fo mb-3" style="max-width:720px">
    <h6 class="fw-bold mb-3"><i class="bi bi-person-lines-fill me-1"></i>Datos personales</h6>
    <div class="row g-3">
        <div class="col-sm-6"><div class="text-muted small">Nombre</div>
            <div class="fw-semibold">{{ empleado.nombre }} {{ empleado.apellidos }}</div></div>
        <div class="col-sm-6"><div class="text-muted small">Cédula</div>
            <div class="fw-semibold">{{ empleado.cedula }}</div></div>
        <div class="col-sm-6"><div class="text-muted small">Puesto</div>
            <div class="fw-semibold">{{ empleado.puesto }}</div></div>
        <div class="col-sm-6"><div class="text-muted small">Fecha de ingreso</div>
            <div class="fw-semibold">{{ empleado.fecha_ingreso }}</div></div>
        <div class="col-sm-6"><div class="text-muted small">Teléfono</div>
            <div class="fw-semibold">{{ empleado.telefono ?: '—' }}</div></div>
        <div class="col-sm-6"><div class="text-muted small">Correo</div>
            <div class="fw-semibold">{{ empleado.correo ?: '—' }}</div></div>
        <div class="col-12"><div class="text-muted small">Dirección</div>
            <div class="fw-semibold">{{ empleado.direccion ?: '—' }}</div></div>
    </div>
</div>

<div class="card-fo" style="max-width:720px">
    <h6 class="fw-bold mb-3"><i class="bi bi-bank me-1"></i>Datos bancarios</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Banco</th><th>Tipo</th><th>Cuenta</th><th>IBAN</th><th>Moneda</th></tr></thead>
        <tbody>
            {% for b in bancos %}
            <tr>
                <td>{{ b.banco }}</td>
                <td>{{ b.tipo_cuenta }}</td>
                <td>{{ b.numero_cuenta }}</td>
                <td>{{ b.numero_cuenta_iban ?: '—' }}</td>
                <td>{{ b.moneda }}</td>
            </tr>
            {% else %}
            <tr><td colspan="5" class="text-center text-muted">Sin cuentas registradas.</td></tr>
            {% endfor %}
        </tbody>
    </table>
    <div class="text-muted small mt-2">
        Si algún dato es incorrecto, repórtelo a la administración (Ley 8968, derecho de rectificación).
    </div>
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 2: Mis Colillas (list)**

Create `templates/portal/mis_colillas.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Mis Colillas{% endblock %}
{% block breadcrumb %}Inicio / Mis Colillas{% endblock %}

{% block contenido %}
{% if not vinculado %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
    Su usuario no está vinculado a un empleado.
</div>
{% else %}
<div class="card-fo" style="max-width:840px">
    <table class="table align-middle">
        <thead>
            <tr><th>Período</th><th>Estado</th>
                <th class="text-end">Bruto</th><th class="text-end">Deducciones</th>
                <th class="text-end">Neto</th><th></th></tr>
        </thead>
        <tbody>
            {% for c in colillas %}
            <tr>
                <td class="fw-semibold">{{ c.fecha_inicio }} — {{ c.fecha_fin }}</td>
                <td><span class="badge {{ c.estado == 'pagado' ? 'text-bg-success' : 'text-bg-primary' }}">{{ c.estado }}</span></td>
                <td class="text-end">{{ c.salario_bruto|number_format(2, '.', ',') }}</td>
                <td class="text-end">{{ c.total_deducciones|number_format(2, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ c.salario_neto|number_format(2, '.', ',') }}</td>
                <td class="text-end">
                    <a href="{{ url_for('portal.colilla', {id: c.id_nomina}) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> Ver
                    </a>
                </td>
            </tr>
            {% else %}
            <tr><td colspan="6" class="text-center text-muted py-4">Aún no tiene colillas disponibles.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 3: Colilla (detail, printable)**

Create `templates/portal/colilla.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Colilla de Pago{% endblock %}
{% block breadcrumb %}Inicio / Mis Colillas / Colilla{% endblock %}

{% block contenido %}
<div class="card-fo p-0" style="max-width:720px">
    <div class="colilla-header">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div>
                <div class="fw-bold">Lubrimotos del Sur — Colilla de pago</div>
                <div class="small">{{ colilla.nombre }} {{ colilla.apellidos }} · {{ colilla.cedula }} · {{ colilla.puesto }}</div>
            </div>
            <div class="text-sm-end">
                <div class="small">Período: {{ colilla.fecha_inicio }} — {{ colilla.fecha_fin }}</div>
                <div class="small">Estado: {{ colilla.estado }}</div>
            </div>
        </div>
    </div>

    <div class="p-3">
        <h6 class="fw-bold mb-2">Ingresos</h6>
        <table class="table table-sm align-middle mb-3">
            <tbody>
                {% for i in colilla.ingresos %}
                <tr>
                    <td>{{ i.tipo|replace({'_': ' '})|capitalize }}{{ i.descripcion ? ' — ' ~ i.descripcion : '' }}</td>
                    <td class="text-end">{{ i.monto|number_format(2, '.', ',') }}</td>
                </tr>
                {% endfor %}
                <tr class="fw-bold"><td>Salario bruto</td>
                    <td class="text-end">{{ colilla.salario_bruto|number_format(2, '.', ',') }}</td></tr>
            </tbody>
        </table>

        <h6 class="fw-bold mb-2">Deducciones</h6>
        <table class="table table-sm align-middle mb-3">
            <tbody>
                {% for d in colilla.deducciones %}
                <tr>
                    <td>{{ d.tipo|replace({'_': ' '})|capitalize }}{{ d.porcentaje ? ' (' ~ d.porcentaje ~ ' %)' : '' }}</td>
                    <td class="text-end">{{ d.monto|number_format(2, '.', ',') }}</td>
                </tr>
                {% else %}
                <tr><td colspan="2" class="text-muted">Sin deducciones.</td></tr>
                {% endfor %}
                <tr class="fw-bold"><td>Total deducciones</td>
                    <td class="text-end">{{ colilla.total_deducciones|number_format(2, '.', ',') }}</td></tr>
            </tbody>
        </table>

        <div class="d-flex justify-content-between align-items-center border-top pt-3">
            <div class="fw-bold fs-5">Salario neto</div>
            <div class="fw-bold fs-5">{{ colilla.salario_neto|number_format(2, '.', ',') }}</div>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mt-3 no-print">
    <a href="{{ url_for('portal.colillas') }}" class="btn btn-outline-primary">Volver</a>
    <button type="button" class="btn btn-primary" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Imprimir
    </button>
</div>
{% endblock %}
```

- [ ] **Step 4: Mis Vacaciones**

Create `templates/portal/mis_vacaciones.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Mis Vacaciones{% endblock %}
{% block breadcrumb %}Inicio / Mis Vacaciones{% endblock %}

{% block contenido %}
{% if not vinculado %}
<div class="card-fo text-center text-muted py-5" style="max-width:720px">
    <i class="bi bi-person-x fs-2 d-block mb-2"></i>
    Su usuario no está vinculado a un empleado.
</div>
{% else %}
<div class="card-fo mb-3" style="max-width:720px">
    <h6 class="fw-bold mb-2"><i class="bi bi-calendar-check me-1"></i>Saldo por año</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Año</th><th class="text-end">Ganados</th>
            <th class="text-end">Disfrutados</th><th class="text-end">Disponibles</th></tr></thead>
        <tbody>
            {% for s in saldos %}
            <tr>
                <td class="fw-semibold">{{ s.anio }}</td>
                <td class="text-end">{{ s.dias_ganados|number_format(1, '.', ',') }}</td>
                <td class="text-end">{{ s.dias_disfrutados|number_format(1, '.', ',') }}</td>
                <td class="text-end fw-bold">{{ s.dias_disponibles|number_format(1, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="4" class="text-center text-muted">Sin saldos registrados.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>

<div class="card-fo" style="max-width:720px">
    <h6 class="fw-bold mb-2"><i class="bi bi-sun me-1"></i>Vacaciones disfrutadas</h6>
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Desde</th><th>Hasta</th><th class="text-end">Días</th></tr></thead>
        <tbody>
            {% for v in historial %}
            <tr>
                <td>{{ v.fecha_inicio }}</td>
                <td>{{ v.fecha_fin }}</td>
                <td class="text-end">{{ v.dias_tomados|number_format(1, '.', ',') }}</td>
            </tr>
            {% else %}
            <tr><td colspan="3" class="text-center text-muted">Aún no ha disfrutado vacaciones.</td></tr>
            {% endfor %}
        </tbody>
    </table>
</div>
{% endif %}
{% endblock %}
```

- [ ] **Step 5: Commit**

```bash
git add templates/portal/
git commit -m "feat(portal): add mi_perfil, mis_colillas, colilla and mis_vacaciones templates"
```

---

### Task 9: Print CSS, DI wiring, routes and sidebar

**Files:**
- Modify: `public/assets/css/app.css` (append at end)
- Modify: `config/dependencies.php` (append before the closing `];`, after the Evaluaciones block)
- Modify: `config/routes.php` (replace the empty `/mi-perfil` group, insert Reportes group before it)
- Modify: `templates/layouts/base.html.twig` (lines 45, 79-81)

- [ ] **Step 1: Print CSS**

Append at the end of `public/assets/css/app.css`:

```css
/* ── Impresión de reportes y colillas ───────────────────── */
@media print {
    .lb-sidebar, .lb-topbar, .lb-backdrop, .no-print { display: none !important; }
    .lb-main { margin-left: 0 !important; }
    body { background: #fff !important; }
    .card-fo { box-shadow: none !important; border: 1px solid #ddd; }
}
```

- [ ] **Step 2: Register in the DI container**

In `config/dependencies.php`, after the `\App\Controllers\EvaluacionesController::class` entry and before the final `];`, add:

```php
    // ── Reportes y Portal ─────────────────────────────────
    \App\Repositories\ReportesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\ReportesRepository($c->get(PDO::class));
    },

    \App\Services\ReportesService::class => function (ContainerInterface $c) {
        return new \App\Services\ReportesService(
            $c->get(\App\Repositories\ReportesRepository::class)
        );
    },

    \App\Controllers\ReportesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\ReportesController(
            $c->get(Twig::class),
            $c->get(\App\Services\ReportesService::class)
        );
    },

    \App\Repositories\PortalRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\PortalRepository($c->get(PDO::class));
    },

    \App\Services\PortalService::class => function (ContainerInterface $c) {
        return new \App\Services\PortalService(
            $c->get(\App\Repositories\PortalRepository::class)
        );
    },

    \App\Controllers\PortalController::class => function (ContainerInterface $c) {
        return new \App\Controllers\PortalController(
            $c->get(Twig::class),
            $c->get(\App\Services\PortalService::class)
        );
    },
```

- [ ] **Step 3: Register routes**

In `config/routes.php`, replace this block:

```php
    // ── Portal colaborador — pendiente ────────────────────
    $app->group('/mi-perfil', function (RouteCollectorProxy $group) {
    })->add(new AuthMiddleware());
```

with:

```php
    // ── Reportes (solo admin) ─────────────────────────────
    $app->group('/reportes', function (RouteCollectorProxy $group) {
        $group->get('',           [\App\Controllers\ReportesController::class, 'index'])->setName('reportes.index');
        $group->get('/planilla',  [\App\Controllers\ReportesController::class, 'planilla'])->setName('reportes.planilla');
        $group->get('/historial', [\App\Controllers\ReportesController::class, 'historial'])->setName('reportes.historial');
        $group->get('/costos',    [\App\Controllers\ReportesController::class, 'costos'])->setName('reportes.costos');
        $group->get('/auditoria', [\App\Controllers\ReportesController::class, 'auditoria'])->setName('reportes.auditoria');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());

    // ── Portal del colaborador (cualquier usuario autenticado) ──
    $app->get('/mi-perfil', [\App\Controllers\PortalController::class, 'perfil'])
        ->setName('portal.perfil')->add(new AuthMiddleware());
    $app->get('/mis-colillas', [\App\Controllers\PortalController::class, 'colillas'])
        ->setName('portal.colillas')->add(new AuthMiddleware());
    $app->get('/mis-colillas/{id}', [\App\Controllers\PortalController::class, 'colilla'])
        ->setName('portal.colilla')->add(new AuthMiddleware());
    $app->get('/mis-vacaciones', [\App\Controllers\PortalController::class, 'vacaciones'])
        ->setName('portal.vacaciones')->add(new AuthMiddleware());
```

- [ ] **Step 4: Wire the sidebar**

In `templates/layouts/base.html.twig`:

Replace line 45:
```twig
            <a class="sb-item" href="#"><i class="bi bi-graph-up"></i><span>Reportes</span></a>
```
with:
```twig
            <a class="sb-item" href="{{ url_for('reportes.index') }}"><i class="bi bi-graph-up"></i><span>Reportes</span></a>
```

Replace the three placeholder lines in the employee group (lines 79-81):
```twig
            <a class="sb-item" href="#"><i class="bi bi-person-lines-fill"></i><span>Mi Perfil</span></a>
            <a class="sb-item" href="#"><i class="bi bi-receipt"></i><span>Mis Colillas</span></a>
            <a class="sb-item" href="#"><i class="bi bi-sun"></i><span>Mis Vacaciones</span></a>
```
with:
```twig
            <a class="sb-item" href="{{ url_for('portal.perfil') }}"><i class="bi bi-person-lines-fill"></i><span>Mi Perfil</span></a>
            <a class="sb-item" href="{{ url_for('portal.colillas') }}"><i class="bi bi-receipt"></i><span>Mis Colillas</span></a>
            <a class="sb-item" href="{{ url_for('portal.vacaciones') }}"><i class="bi bi-sun"></i><span>Mis Vacaciones</span></a>
```

- [ ] **Step 5: Syntax checks and full test suite**

Run: `php -l config/dependencies.php; php -l config/routes.php`
Expected: `No syntax errors detected` (both)

Run: `vendor\bin\phpunit`
Expected: all 24 tests green (this module adds no unit tests — read-only SQL, no pure calculation)

- [ ] **Step 6: Commit**

```bash
git add public/assets/css/app.css config/dependencies.php config/routes.php templates/layouts/base.html.twig
git commit -m "feat(reportes): wire print CSS, DI, routes and sidebar links"
```

---

### Task 10: Smoke test and mark module complete

**Files:**
- Modify: `CLAUDE.md` (module status table, row 13)

- [ ] **Step 1: Boot the app and smoke-test routes**

Run (background): `php -S localhost:8080 -t public public/index.php`

Then verify with `curl.exe -s -o NUL -w "%{http_code}"` that each route returns **302** (redirect to /login — middleware active, route resolves, no 500):

- `http://localhost:8080/reportes`
- `http://localhost:8080/reportes/planilla`
- `http://localhost:8080/reportes/historial`
- `http://localhost:8080/reportes/costos`
- `http://localhost:8080/reportes/auditoria`
- `http://localhost:8080/mi-perfil`
- `http://localhost:8080/mis-colillas`
- `http://localhost:8080/mis-colillas/1`
- `http://localhost:8080/mis-vacaciones`

Stop the server afterwards.

- [ ] **Step 2: Update module status in CLAUDE.md**

In the module table, change row 13 from:
```
| 13 | Consultas / Reportes | `feature/reportes` | 🔲 Pendiente |
```
to:
```
| 13 | Consultas / Reportes | `feature/reportes` | ✅ Completo (pendiente prueba manual) |
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: mark Reportes module complete"
```

---

## Self-review notes

- Spec coverage: 4 admin reports + hub (Tasks 1-4), portal with the 3 views + printable colilla (Tasks 5-8), print CSS + GET filters + sidebar wiring (Task 9), smoke test + status row (Task 10). Spec §3.2 was amended: horas extra shows fecha/cantidad/factor (no stored monto per overtime row).
- Security invariant: every PortalRepository query joins `empleados.id_usuario = :u`; `colilla()` additionally restricts to estados `aprobado`/`pagado`, so drafts and foreign payslips both 302-redirect with a flash.
- Filter validation lives in ReportesService (`idValido`, `rangoFechas`, ENUM whitelist for `accion`); repositories receive only sanitized values. The auditoría LIMIT is concatenated from a class constant (int), never from user input.
- `nominas.total_deducciones`/`salario_neto` already exclude the informative `CCSS_patronal` line (verified in NominasService), so planilla reads totals directly and costos sums the patronal lines separately — no double counting.
