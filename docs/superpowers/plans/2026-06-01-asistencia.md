# Asistencia Module Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`).

**Goal:** Build the Asistencia CRUD module — daily attendance per employee, auto-computed worked hours, auto-resolved pay-period and holiday, soft constraints, audited.

**Architecture:** Controller (HTTP only, `RouteContext::urlFor` redirects) → Service (validation, auto-resolution, audit) → Repository (raw PDO). Twig templates extend `layouts/base.html.twig`. slim/twig-view 3.4 API: `url_for`/`base_path` in templates.

**Tech Stack:** PHP 8.1, Slim 4, raw PDO (MySQL 8), Twig 3, PHP-DI 7, Bootstrap 5.

**Reference:** Empleados module (same patterns). Spec: `docs/superpowers/specs/2026-06-01-asistencia-design.md`.

**Schema (`asistencia`):** `id_asistencia` PK, `id_empleado` FK NOT NULL, `id_periodo` FK NOT NULL, `id_feriado` FK NULL, `fecha` DATE, `hora_entrada` TIME NULL, `hora_salida` TIME NULL, `horas_trabajadas` DECIMAL(4,2). `UNIQUE(id_empleado, fecha)`. `periodos_pago(id_periodo, fecha_inicio, fecha_fin, estado)`. `feriados(id_feriado, fecha, nombre, tipo)`.

---

## Task 1: AsistenciaRepository

**Files:** Create `src/Repositories/AsistenciaRepository.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AsistenciaRepository — acceso SQL a la tabla `asistencia` y lookups
 * de periodo/feriado por fecha. Sin lógica de negocio.
 */
class AsistenciaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT a.id_asistencia, a.id_empleado, a.id_periodo, a.id_feriado,
                       a.fecha, a.hora_entrada, a.hora_salida, a.horas_trabajadas,
                       e.nombre, e.apellidos, f.nombre AS feriado_nombre
                  FROM asistencia a
                  JOIN empleados e ON e.id_empleado = a.id_empleado
             LEFT JOIN feriados  f ON f.id_feriado  = a.id_feriado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'a.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'a.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.fecha DESC, e.apellidos ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, e.nombre, e.apellidos, f.nombre AS feriado_nombre
               FROM asistencia a
               JOIN empleados e ON e.id_empleado = a.id_empleado
          LEFT JOIN feriados  f ON f.id_feriado  = a.id_feriado
              WHERE a.id_asistencia = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsByEmpleadoFecha(int $idEmpleado, string $fecha, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM asistencia WHERE id_empleado = :emp AND fecha = :fecha';
        $params = [':emp' => $idEmpleado, ':fecha' => $fecha];
        if ($excludeId !== null) {
            $sql .= ' AND id_asistencia <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Periodo en estado 'abierto' que contiene la fecha, o null.
     *
     * @return array<string, mixed>|null
     */
    public function findPeriodoAbiertoPorFecha(string $fecha): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id_periodo FROM periodos_pago
              WHERE :fecha BETWEEN fecha_inicio AND fecha_fin AND estado = 'abierto'
              LIMIT 1"
        );
        $stmt->execute([':fecha' => $fecha]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function findFeriadoIdPorFecha(string $fecha): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id_feriado FROM feriados WHERE fecha = :fecha LIMIT 1');
        $stmt->execute([':fecha' => $fecha]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO asistencia
                (id_empleado, id_periodo, id_feriado, fecha, hora_entrada, hora_salida, horas_trabajadas)
             VALUES
                (:id_empleado, :id_periodo, :id_feriado, :fecha, :hora_entrada, :hora_salida, :horas_trabajadas)'
        );
        $stmt->execute($this->bind($d));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE asistencia SET
                id_empleado = :id_empleado, id_periodo = :id_periodo, id_feriado = :id_feriado,
                fecha = :fecha, hora_entrada = :hora_entrada, hora_salida = :hora_salida,
                horas_trabajadas = :horas_trabajadas
              WHERE id_asistencia = :id'
        );
        $params = $this->bind($d);
        $params[':id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM asistencia WHERE id_asistencia = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'      => $d['id_empleado'],
            ':id_periodo'       => $d['id_periodo'],
            ':id_feriado'       => $d['id_feriado'],
            ':fecha'            => $d['fecha'],
            ':hora_entrada'     => $d['hora_entrada'],
            ':hora_salida'      => $d['hora_salida'],
            ':horas_trabajadas' => $d['horas_trabajadas'],
        ];
    }
}
```

- [ ] **Step 2:** `php -l src/Repositories/AsistenciaRepository.php` → `No syntax errors detected`
- [ ] **Step 3:** Commit: `git add src/Repositories/AsistenciaRepository.php && git commit -m "feat: add AsistenciaRepository"`

---

## Task 2: AsistenciaService

**Files:** Create `src/Services/AsistenciaService.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * AsistenciaService — lógica de negocio del módulo de asistencia.
 * No accede a PDO ni a $_SESSION/$_SERVER (loggedInId/ip llegan como parámetros).
 */
class AsistenciaService
{
    public function __construct(
        private readonly AsistenciaRepository $repo,
        private readonly EmpleadosRepository  $empleadosRepo,
        private readonly PeriodosRepository   $periodosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Registro de asistencia no encontrado.');
        }
        return $row;
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>, periodos: array<int, array<string, mixed>>}
     */
    public function datosFormulario(): array
    {
        return [
            'empleados' => $this->empleadosRepo->findAll('activo'),
            'periodos'  => $this->periodosRepo->findAll(),
        ];
    }

    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $fila = $this->validar($datos, null);
        try {
            $id = $this->repo->insert($fila);
        } catch (PDOException $e) {
            throw $this->traducir($e);
        }
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'asistencia', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $fila = $this->validar($datos, $id);
        try {
            $this->repo->update($id, $fila);
        } catch (PDOException $e) {
            throw $this->traducir($e);
        }
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'asistencia', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'asistencia', $id, $ip);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d, ?int $excludeId): array
    {
        $errores = [];

        $idEmpleado = isset($d['id_empleado']) && is_numeric($d['id_empleado']) ? (int)$d['id_empleado'] : 0;
        $empleado   = $idEmpleado > 0 ? $this->empleadosRepo->findById($idEmpleado) : null;
        if ($empleado === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        } elseif (($empleado['estado'] ?? '') !== 'activo') {
            $errores[] = 'El empleado seleccionado no está activo.';
        }

        $fecha    = trim((string)($d['fecha'] ?? ''));
        $fechaObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($fechaObj === false) {
            $errores[] = 'La fecha es obligatoria y debe tener formato válido.';
        } elseif ($fechaObj > new DateTimeImmutable('today')) {
            $errores[] = 'La fecha no puede ser futura.';
        }

        $entrada    = trim((string)($d['hora_entrada'] ?? ''));
        $salida     = trim((string)($d['hora_salida'] ?? ''));
        $entradaObj = $entrada !== '' ? DateTimeImmutable::createFromFormat('!H:i', $entrada) : false;
        $salidaObj  = $salida !== ''  ? DateTimeImmutable::createFromFormat('!H:i', $salida)  : false;
        if ($entradaObj === false) {
            $errores[] = 'La hora de entrada es obligatoria (formato HH:MM).';
        }
        if ($salidaObj === false) {
            $errores[] = 'La hora de salida es obligatoria (formato HH:MM).';
        }
        if ($entradaObj !== false && $salidaObj !== false && $salidaObj <= $entradaObj) {
            $errores[] = 'La hora de salida debe ser posterior a la de entrada.';
        }

        // horas_trabajadas: override si el admin manda un valor > 0; si no, se calcula.
        $horas    = 0.0;
        $horasRaw = $d['horas_trabajadas'] ?? '';
        if (is_numeric($horasRaw) && (float)$horasRaw > 0) {
            $horas = round((float)$horasRaw, 2);
        } elseif ($entradaObj !== false && $salidaObj !== false && $salidaObj > $entradaObj) {
            $horas = round(($salidaObj->getTimestamp() - $entradaObj->getTimestamp()) / 3600, 2);
        }
        if ($horas <= 0 || $horas > 24) {
            $errores[] = 'Las horas trabajadas deben estar entre 0 y 24.';
        }

        // Periodo abierto que contiene la fecha.
        $idPeriodo = null;
        if ($fechaObj !== false) {
            $periodo = $this->repo->findPeriodoAbiertoPorFecha($fecha);
            if ($periodo === null) {
                $errores[] = 'No hay un periodo de pago abierto que contenga esa fecha.';
            } else {
                $idPeriodo = (int)$periodo['id_periodo'];
            }
        }

        // Duplicado (empleado, fecha).
        if ($idEmpleado > 0 && $fechaObj !== false
            && $this->repo->existsByEmpleadoFecha($idEmpleado, $fecha, $excludeId)) {
            $errores[] = 'Ya existe un registro de asistencia para ese empleado en esa fecha.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $idFeriado = $this->repo->findFeriadoIdPorFecha($fecha);

        return [
            'id_empleado'      => $idEmpleado,
            'id_periodo'       => $idPeriodo,
            'id_feriado'       => $idFeriado,
            'fecha'            => $fecha,
            'hora_entrada'     => $entrada,
            'hora_salida'      => $salida,
            'horas_trabajadas' => $horas,
        ];
    }

    private function traducir(PDOException $e): InvalidArgumentException
    {
        if (str_starts_with((string)$e->getCode(), '23')) {
            return new InvalidArgumentException('Ya existe un registro de asistencia para ese empleado en esa fecha.');
        }
        throw $e;
    }
}
```

- [ ] **Step 2:** `php -l src/Services/AsistenciaService.php` → `No syntax errors detected`
- [ ] **Step 3:** Commit: `git add src/Services/AsistenciaService.php && git commit -m "feat: add AsistenciaService with auto period/holiday resolution and hour calc"`

---

## Task 3: AsistenciaController

**Files:** Create `src/Controllers/AsistenciaController.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AsistenciaService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class AsistenciaController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly AsistenciaService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;

        $datosForm = $this->service->datosFormulario();

        return $this->twig->render($response, 'asistencia/index.html.twig', [
            'titulo'         => 'Asistencia',
            'asistencias'    => $this->service->listar($idPeriodo, $idEmpleado),
            'empleados'      => $datosForm['empleados'],
            'periodos'       => $datosForm['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'asistencia/form.html.twig', [
            'titulo'     => 'Registrar Asistencia',
            'accion'     => 'crear',
            'asistencia' => [],
            'empleados'  => $datosForm['empleados'],
            'errores'    => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Asistencia registrada exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'asistencia.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'asistencia/form.html.twig', [
                'titulo'     => 'Registrar Asistencia',
                'accion'     => 'crear',
                'asistencia' => $datos,
                'empleados'  => $datosForm['empleados'],
                'errores'    => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $asistencia = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Registro de asistencia no encontrado.';
            return $response->withHeader('Location', $this->urlFor($request, 'asistencia.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'asistencia/form.html.twig', [
            'titulo'     => 'Editar Asistencia',
            'accion'     => 'editar',
            'asistencia' => $asistencia,
            'empleados'  => $datosForm['empleados'],
            'errores'    => [],
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
            $_SESSION['flash_success'] = 'Asistencia actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'asistencia.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'asistencia/form.html.twig', [
                'titulo'     => 'Editar Asistencia',
                'accion'     => 'editar',
                'asistencia' => array_merge(['id_asistencia' => $id], $datos),
                'empleados'  => $datosForm['empleados'],
                'errores'    => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'asistencia.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Registro de asistencia eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'asistencia.index'))->withStatus(302);
    }

    private function urlFor(Request $request, string $routeName): string
    {
        return RouteContext::fromRequest($request)->getRouteParser()->urlFor($routeName);
    }

    private function consumeFlash(string $key): ?string
    {
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }
}
```

- [ ] **Step 2:** `php -l src/Controllers/AsistenciaController.php` → `No syntax errors detected`
- [ ] **Step 3:** Commit: `git add src/Controllers/AsistenciaController.php && git commit -m "feat: add AsistenciaController with period/employee filters"`

---

## Task 4: index template

**Files:** Create `templates/asistencia/index.html.twig`

- [ ] **Step 1: Write the file**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Asistencia{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Operaciones</li>
        <li class="breadcrumb-item active">Asistencia</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Asistencia</h2>
    <a href="{{ url_for('asistencia.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Registrar Asistencia
    </a>
</div>

<form method="GET" action="{{ url_for('asistencia.index') }}" class="row g-2 mb-3">
    <div class="col-md-5">
        <select name="periodo" class="form-select form-select-sm">
            <option value="">— Todos los periodos —</option>
            {% for p in periodos %}
            <option value="{{ p.id_periodo }}" {{ filtroPeriodo == p.id_periodo ? 'selected' : '' }}>
                {{ p.fecha_inicio|date('d/m/Y') }} – {{ p.fecha_fin|date('d/m/Y') }} ({{ p.estado }})
            </option>
            {% endfor %}
        </select>
    </div>
    <div class="col-md-5">
        <select name="empleado" class="form-select form-select-sm">
            <option value="">— Todos los empleados —</option>
            {% for e in empleados %}
            <option value="{{ e.id_empleado }}" {{ filtroEmpleado == e.id_empleado ? 'selected' : '' }}>
                {{ e.apellidos }}, {{ e.nombre }}
            </option>
            {% endfor %}
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
            <i class="bi bi-funnel me-1"></i>Filtrar
        </button>
    </div>
</form>

{% if asistencias|length == 0 %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay registros de asistencia.</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th>
                    <th>Fecha</th>
                    <th>Entrada</th>
                    <th>Salida</th>
                    <th class="text-end">Horas</th>
                    <th>Feriado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for a in asistencias %}
                <tr>
                    <td class="fw-semibold">{{ a.apellidos }}, {{ a.nombre }}</td>
                    <td>{{ a.fecha|date('d/m/Y') }}</td>
                    <td>{{ a.hora_entrada }}</td>
                    <td>{{ a.hora_salida }}</td>
                    <td class="text-end">{{ a.horas_trabajadas }}</td>
                    <td>{% if a.feriado_nombre %}<span class="badge bg-warning text-dark">{{ a.feriado_nombre }}</span>{% endif %}</td>
                    <td class="text-center">
                        <a href="{{ url_for('asistencia.edit', {'id': a.id_asistencia}) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-id="{{ a.id_asistencia }}"
                                data-info="{{ a.apellidos }}, {{ a.nombre }} — {{ a.fecha|date('d/m/Y') }}">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ asistencias|length }} registro(s).</div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar el registro de <strong id="modalInfo"></strong>? Esta acción no se puede deshacer.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <form id="formEliminar" method="POST" action="">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Eliminar</button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}

{% block scripts %}
<script>
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('modalInfo').textContent = btn.getAttribute('data-info');
    document.getElementById('formEliminar').action = '/asistencia/' + btn.getAttribute('data-id') + '/eliminar';
});
</script>
{% endblock %}
```

- [ ] **Step 2:** Verify Twig tags balanced (blocks, if/endif, for/endfor).
- [ ] **Step 3:** Commit: `git add templates/asistencia/index.html.twig && git commit -m "feat: add asistencia index template with filters"`

---

## Task 5: form template

**Files:** Create `templates/asistencia/form.html.twig`

- [ ] **Step 1: Write the file**

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ url_for('asistencia.index') }}">Asistencia</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Registrar' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger">
                    <ul class="mb-0">{% for error in errores %}<li>{{ error }}</li>{% endfor %}</ul>
                </div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('asistencia.store') %}
                {% else %}
                    {% set form_action = url_for('asistencia.update', {'id': asistencia.id_asistencia}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="id_empleado" class="form-label fw-semibold">Empleado <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_empleado" name="id_empleado" required>
                            <option value="">— Seleccione —</option>
                            {% for e in empleados %}
                            <option value="{{ e.id_empleado }}"
                                {{ (asistencia.id_empleado ?? '') == e.id_empleado ? 'selected' : '' }}>
                                {{ e.apellidos }}, {{ e.nombre }}
                            </option>
                            {% endfor %}
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="fecha" class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="fecha" name="fecha" required
                               value="{{ asistencia.fecha ?? '' }}">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="hora_entrada" class="form-label fw-semibold">Hora de entrada <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="hora_entrada" name="hora_entrada" required
                                   value="{{ asistencia.hora_entrada ?? '' }}">
                        </div>
                        <div class="col-md-6">
                            <label for="hora_salida" class="form-label fw-semibold">Hora de salida <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" id="hora_salida" name="hora_salida" required
                                   value="{{ asistencia.hora_salida ?? '' }}">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label for="horas_trabajadas" class="form-label fw-semibold">Horas trabajadas</label>
                        <input type="number" step="0.01" min="0" max="24" class="form-control"
                               id="horas_trabajadas" name="horas_trabajadas"
                               value="{{ asistencia.horas_trabajadas ?? '' }}">
                        <div class="form-text">Dejar vacío para calcular automáticamente como (salida − entrada). El periodo y el feriado se asignan según la fecha.</div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ url_for('asistencia.index') }}" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Cancelar
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar' : 'Actualizar' }}
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

- [ ] **Step 2:** Verify Twig tags balanced.
- [ ] **Step 3:** Commit: `git add templates/asistencia/form.html.twig && git commit -m "feat: add asistencia form template"`

---

## Task 6: Wire DI + routes + navbar

**Files:** Modify `config/dependencies.php`, `config/routes.php`, `templates/layouts/base.html.twig`

- [ ] **Step 1: dependencies.php** — add at the end of each section (after the Empleados entries):

```php
    \App\Repositories\AsistenciaRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\AsistenciaRepository($c->get(PDO::class));
    },
```
```php
    \App\Services\AsistenciaService::class => function (ContainerInterface $c) {
        return new \App\Services\AsistenciaService(
            $c->get(\App\Repositories\AsistenciaRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```
```php
    \App\Controllers\AsistenciaController::class => function (ContainerInterface $c) {
        return new \App\Controllers\AsistenciaController(
            $c->get(Twig::class),
            $c->get(\App\Services\AsistenciaService::class)
        );
    },
```

- [ ] **Step 2: routes.php** — add import after the other controller imports:
```php
use App\Controllers\AsistenciaController;
```
And add a NEW route group after the `/empleados` group (and before `/nominas`):
```php
    // ── Asistencia (solo admin) ───────────────────────────
    $app->group('/asistencia', function (RouteCollectorProxy $group) {
        $group->get('',                  [AsistenciaController::class, 'index'])->setName('asistencia.index');
        $group->get('/crear',            [AsistenciaController::class, 'create'])->setName('asistencia.create');
        $group->post('/crear',           [AsistenciaController::class, 'store'])->setName('asistencia.store');
        $group->get('/{id}/editar',      [AsistenciaController::class, 'edit'])->setName('asistencia.edit');
        $group->post('/{id}/editar',     [AsistenciaController::class, 'update'])->setName('asistencia.update');
        $group->post('/{id}/eliminar',   [AsistenciaController::class, 'destroy'])->setName('asistencia.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

- [ ] **Step 3: base.html.twig** — in the "Operaciones" dropdown, change the Asistencia item from a `#` href to the route. Find:
```twig
                        <li><a class="dropdown-item" href="#"><i class="bi bi-clock-history me-1"></i>Asistencia</a></li>
```
Replace with:
```twig
                        <li><a class="dropdown-item" href="{{ url_for('asistencia.index') }}"><i class="bi bi-clock-history me-1"></i>Asistencia</a></li>
```
(Leave the other Operaciones items — Horas Extra, Vacaciones, Incapacidades, Permisos — as `#` for now.)

- [ ] **Step 4:** `php -l config/dependencies.php && php -l config/routes.php` → both `No syntax errors detected`
- [ ] **Step 5:** Commit: `git add config/dependencies.php config/routes.php templates/layouts/base.html.twig && git commit -m "feat: wire Asistencia (DI, routes, navbar)"`

---

## Task 7: Integration verification (controller-run)

- [ ] Boot the container + register routes (no DB) and confirm the route parser resolves `asistencia.index`, `asistencia.create`, `asistencia.edit({id:1})`, `asistencia.update({id:1})`, `asistencia.store`, `asistencia.destroy`. Confirm no `path_for`/`base_url` introduced.

## Task 8: Manual smoke test (user, XAMPP)
Requires an open `periodos_pago` row covering the test date, an active employee, and (optionally) a feriado. Verify: create (auto hours + auto period), validation (future date, no open period, duplicate), edit, delete, filters, and the `auditoria` rows.
