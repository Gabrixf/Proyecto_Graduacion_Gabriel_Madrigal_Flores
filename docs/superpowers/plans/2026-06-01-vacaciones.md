# Vacaciones Module Implementation Plan

> Use superpowers:subagent-driven-development. Verbatim code. API: `url_for`/`base_path`, `RouteContext::urlFor`, modal `data-url`. No `path_for`/`base_url`.

**Goal:** Vacaciones CRUD derived from approved `vacaciones` solicitudes; auto period; dias_tomados = calendar days (inclusive) with override; transactional update of `saldo_vacaciones` (no hard block); one vacación per solicitud; audited; admin-only.

**Schema:** `vacaciones`(id_vacacion PK, id_solicitud FK NOT NULL, id_empleado FK, id_periodo FK, fecha_inicio DATE, fecha_fin DATE, dias_tomados DECIMAL(4,1)). `saldo_vacaciones`(id_saldo PK, id_empleado FK, anio INT, dias_ganados/dias_disfrutados/dias_disponibles DECIMAL(5,1), UNIQUE(id_empleado,anio)).

Route prefix `vacaciones.*`, group `/vacaciones`.

---

## Task 1: VacacionesRepository — `src/Repositories/VacacionesRepository.php`

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * VacacionesRepository — SQL para `vacaciones` y mantenimiento de `saldo_vacaciones`.
 * Las escrituras envuelven ambas tablas en una transacción (atomicidad de persistencia).
 */
class VacacionesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT v.id_vacacion, v.id_solicitud, v.id_empleado, v.id_periodo,
                       v.fecha_inicio, v.fecha_fin, v.dias_tomados, e.nombre, e.apellidos
                  FROM vacaciones v
                  JOIN empleados e ON e.id_empleado = v.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'v.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'v.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY v.fecha_inicio DESC, e.apellidos ASC';

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
            "SELECT v.*, e.nombre, e.apellidos
               FROM vacaciones v
               JOIN empleados e ON e.id_empleado = v.id_empleado
              WHERE v.id_vacacion = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM vacaciones WHERE id_solicitud = :s';
        $params = [':s' => $idSolicitud];
        if ($excludeId !== null) {
            $sql .= ' AND id_vacacion <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findSolicitudesDisponibles(?int $incluirId = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.fecha_fin, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
             LEFT JOIN vacaciones v ON v.id_solicitud = s.id_solicitud
                 WHERE s.tipo = 'vacaciones' AND s.estado = 'aprobada'
                   AND (v.id_vacacion IS NULL";
        $params = [];
        if ($incluirId !== null) {
            $sql .= ' OR s.id_solicitud = :incluir';
            $params[':incluir'] = $incluirId;
        }
        $sql .= ') ORDER BY s.fecha_inicio DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
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

    /**
     * @return array<string, mixed>|null
     */
    public function findSaldo(int $idEmpleado, int $anio): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_saldo, dias_ganados, dias_disfrutados, dias_disponibles
               FROM saldo_vacaciones WHERE id_empleado = :e AND anio = :a LIMIT 1'
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $d  requiere claves: id_solicitud, id_empleado, id_periodo,
     *                                  fecha_inicio, fecha_fin, dias_tomados, anio
     */
    public function insert(array $d): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO vacaciones (id_solicitud, id_empleado, id_periodo, fecha_inicio, fecha_fin, dias_tomados)
                 VALUES (:id_solicitud, :id_empleado, :id_periodo, :fecha_inicio, :fecha_fin, :dias_tomados)'
            );
            $stmt->execute([
                ':id_solicitud' => $d['id_solicitud'],
                ':id_empleado'  => $d['id_empleado'],
                ':id_periodo'   => $d['id_periodo'],
                ':fecha_inicio' => $d['fecha_inicio'],
                ':fecha_fin'    => $d['fecha_fin'],
                ':dias_tomados' => $d['dias_tomados'],
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->ajustarSaldo((int)$d['id_empleado'], (int)$d['anio'], (float)$d['dias_tomados']);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, float $nuevoDias): void
    {
        $this->pdo->beginTransaction();
        try {
            $cur = $this->pdo->prepare(
                'SELECT id_empleado, fecha_inicio, dias_tomados FROM vacaciones WHERE id_vacacion = :id LIMIT 1'
            );
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if ($row !== false) {
                $oldDias = (float)$row['dias_tomados'];
                $anio    = (int)substr((string)$row['fecha_inicio'], 0, 4);
                $upd = $this->pdo->prepare('UPDATE vacaciones SET dias_tomados = :d WHERE id_vacacion = :id');
                $upd->execute([':d' => $nuevoDias, ':id' => $id]);
                $this->ajustarSaldo((int)$row['id_empleado'], $anio, $nuevoDias - $oldDias);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $cur = $this->pdo->prepare(
                'SELECT id_empleado, fecha_inicio, dias_tomados FROM vacaciones WHERE id_vacacion = :id LIMIT 1'
            );
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if ($row !== false) {
                $anio = (int)substr((string)$row['fecha_inicio'], 0, 4);
                $del = $this->pdo->prepare('DELETE FROM vacaciones WHERE id_vacacion = :id');
                $del->execute([':id' => $id]);
                $this->ajustarSaldo((int)$row['id_empleado'], $anio, -(float)$row['dias_tomados']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Upsert del saldo del año: suma delta a disfrutados y recalcula disponibles.
     * Debe llamarse dentro de una transacción activa.
     */
    private function ajustarSaldo(int $idEmpleado, int $anio, float $delta): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_saldo, dias_ganados, dias_disfrutados FROM saldo_vacaciones
              WHERE id_empleado = :e AND anio = :a LIMIT 1'
        );
        $stmt->execute([':e' => $idEmpleado, ':a' => $anio]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $disfrutados = (float)$row['dias_disfrutados'] + $delta;
            if ($disfrutados < 0) {
                $disfrutados = 0.0;
            }
            $disponibles = (float)$row['dias_ganados'] - $disfrutados;
            $upd = $this->pdo->prepare(
                'UPDATE saldo_vacaciones SET dias_disfrutados = :d, dias_disponibles = :p WHERE id_saldo = :id'
            );
            $upd->execute([':d' => $disfrutados, ':p' => $disponibles, ':id' => (int)$row['id_saldo']]);
        } else {
            $disfrutados = $delta > 0 ? $delta : 0.0;
            $disponibles = 0.0 - $disfrutados;
            $ins = $this->pdo->prepare(
                'INSERT INTO saldo_vacaciones (id_empleado, anio, dias_ganados, dias_disfrutados, dias_disponibles)
                 VALUES (:e, :a, 0, :d, :p)'
            );
            $ins->execute([':e' => $idEmpleado, ':a' => $anio, ':d' => $disfrutados, ':p' => $disponibles]);
        }
    }
}
```

Lint, commit `feat: add VacacionesRepository with transactional saldo update`.

---

## Task 2: VacacionesService — `src/Services/VacacionesService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Repositories\VacacionesRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * VacacionesService — registro de vacaciones disfrutadas y actualización de saldo.
 */
class VacacionesService
{
    public function __construct(
        private readonly VacacionesRepository  $repo,
        private readonly SolicitudesRepository $solicitudesRepo,
        private readonly EmpleadosRepository   $empleadosRepo,
        private readonly PeriodosRepository    $periodosRepo,
        private readonly AuditoriaRepository   $auditoriaRepo
    ) {}

    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado);
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Registro de vacaciones no encontrado.');
        }
        return $row;
    }

    /**
     * @return array{solicitudes: array<int, array<string, mixed>>}
     */
    public function datosFormulario(?int $incluirId = null): array
    {
        return ['solicitudes' => $this->repo->findSolicitudesDisponibles($incluirId)];
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>, periodos: array<int, array<string, mixed>>}
     */
    public function datosFiltros(): array
    {
        return [
            'empleados' => $this->empleadosRepo->findAll('activo'),
            'periodos'  => $this->periodosRepo->findAll(),
        ];
    }

    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $idSolicitud = isset($datos['id_solicitud']) && is_numeric($datos['id_solicitud']) ? (int)$datos['id_solicitud'] : 0;
        $solicitud   = $idSolicitud > 0 ? $this->solicitudesRepo->findById($idSolicitud) : null;

        $errores = [];
        if ($solicitud === null) {
            $errores[] = 'Debe seleccionar una solicitud válida.';
        } else {
            if (($solicitud['tipo'] ?? '') !== 'vacaciones') {
                $errores[] = 'La solicitud seleccionada no es de tipo vacaciones.';
            }
            if (($solicitud['estado'] ?? '') !== 'aprobada') {
                $errores[] = 'La solicitud debe estar aprobada.';
            }
            if (empty($solicitud['fecha_fin'])) {
                $errores[] = 'La solicitud de vacaciones no tiene fecha de fin.';
            }
            if ($this->repo->existsBySolicitud($idSolicitud)) {
                $errores[] = 'Esta solicitud ya tiene vacaciones registradas.';
            }
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaInicio = (string)$solicitud['fecha_inicio'];
        $fechaFin    = (string)$solicitud['fecha_fin'];
        $idEmpleado  = (int)$solicitud['id_empleado'];

        $errores = [];
        $dias    = $this->resolverDias($datos['dias_tomados'] ?? '', $fechaInicio, $fechaFin, $errores);
        $periodo = $this->repo->findPeriodoAbiertoPorFecha($fechaInicio);
        if ($periodo === null) {
            $errores[] = 'No hay un periodo de pago abierto que contenga la fecha de inicio.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $id = $this->repo->insert([
            'id_solicitud' => $idSolicitud,
            'id_empleado'  => $idEmpleado,
            'id_periodo'   => (int)$periodo['id_periodo'],
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $fechaFin,
            'dias_tomados' => $dias,
            'anio'         => (int)substr($fechaInicio, 0, 4),
        ]);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'vacaciones', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $vac     = $this->obtener($id);
        $errores = [];
        $dias    = $this->resolverDias(
            $datos['dias_tomados'] ?? '',
            (string)$vac['fecha_inicio'],
            (string)$vac['fecha_fin'],
            $errores
        );
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }
        $this->repo->update($id, $dias);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'vacaciones', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'vacaciones', $id, $ip);
    }

    /**
     * Devuelve el saldo resultante para el aviso: {anio, dias_disponibles} o null.
     *
     * @return array<string, mixed>|null
     */
    public function saldoDeVacacion(int $idVacacion): ?array
    {
        $vac = $this->repo->findById($idVacacion);
        if ($vac === null) {
            return null;
        }
        $anio  = (int)substr((string)$vac['fecha_inicio'], 0, 4);
        $saldo = $this->repo->findSaldo((int)$vac['id_empleado'], $anio);
        if ($saldo === null) {
            return null;
        }
        return ['anio' => $anio, 'dias_disponibles' => (float)$saldo['dias_disponibles']];
    }

    /**
     * @param string[] $errores
     */
    private function resolverDias(mixed $raw, string $ini, string $fin, array &$errores): float
    {
        if (is_numeric($raw) && (float)$raw > 0) {
            return round((float)$raw, 1);
        }
        $iniObj = DateTimeImmutable::createFromFormat('!Y-m-d', $ini);
        $finObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($iniObj === false || $finObj === false || $finObj < $iniObj) {
            $errores[] = 'El rango de fechas de la solicitud no es válido.';
            return 0.0;
        }
        return (float)($finObj->diff($iniObj)->days + 1);
    }
}
```

Lint, commit `feat: add VacacionesService with saldo balance and day computation`.

---

## Task 3: VacacionesController — `src/Controllers/VacacionesController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\VacacionesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class VacacionesController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly VacacionesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'vacaciones/index.html.twig', [
            'titulo'         => 'Vacaciones',
            'vacaciones'     => $this->service->listar($idPeriodo, $idEmpleado),
            'empleados'      => $filtros['empleados'],
            'periodos'       => $filtros['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'vacaciones/form.html.twig', [
            'titulo'      => 'Registrar Vacaciones',
            'accion'      => 'crear',
            'vacacion'    => [],
            'solicitudes' => $datosForm['solicitudes'],
            'errores'     => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $id = $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Vacaciones registradas exitosamente.' . $this->avisoSaldo($id);
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'vacaciones/form.html.twig', [
                'titulo'      => 'Registrar Vacaciones',
                'accion'      => 'crear',
                'vacacion'    => $datos,
                'solicitudes' => $datosForm['solicitudes'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $vacacion = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Registro de vacaciones no encontrado.';
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario((int)$vacacion['id_solicitud']);
        return $this->twig->render($response, 'vacaciones/form.html.twig', [
            'titulo'      => 'Editar Vacaciones',
            'accion'      => 'editar',
            'vacacion'    => $vacacion,
            'solicitudes' => $datosForm['solicitudes'],
            'errores'     => [],
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
            $_SESSION['flash_success'] = 'Vacaciones actualizadas correctamente.' . $this->avisoSaldo($id);
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            try {
                $vacacion = $this->service->obtener($id);
            } catch (RuntimeException) {
                $_SESSION['flash_error'] = 'Registro de vacaciones no encontrado.';
                return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
            }
            return $this->twig->render($response->withStatus(422), 'vacaciones/form.html.twig', [
                'titulo'      => 'Editar Vacaciones',
                'accion'      => 'editar',
                'vacacion'    => array_merge($vacacion, $datos),
                'solicitudes' => [],
                'errores'     => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Registro de vacaciones eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'vacaciones.index'))->withStatus(302);
    }

    private function avisoSaldo(int $idVacacion): string
    {
        $saldo = $this->service->saldoDeVacacion($idVacacion);
        if ($saldo === null) {
            return '';
        }
        $disp = (float)$saldo['dias_disponibles'];
        if ($disp < 0) {
            return ' Atención: el saldo ' . $saldo['anio'] . ' quedó en déficit (' . $disp . ' días).';
        }
        return ' Saldo disponible ' . $saldo['anio'] . ': ' . $disp . ' días.';
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

Lint, commit `feat: add VacacionesController with saldo notice`.

---

## Task 4: index template — `templates/vacaciones/index.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Vacaciones{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Operaciones</li>
        <li class="breadcrumb-item active">Vacaciones</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-sun me-2 text-primary"></i>Vacaciones</h2>
    <a href="{{ url_for('vacaciones.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Registrar Vacaciones
    </a>
</div>

<form method="GET" action="{{ url_for('vacaciones.index') }}" class="row g-2 mb-3">
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
            <option value="{{ e.id_empleado }}" {{ filtroEmpleado == e.id_empleado ? 'selected' : '' }}>{{ e.apellidos }}, {{ e.nombre }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </div>
</form>

{% if vacaciones|length == 0 %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay registros de vacaciones.</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th><th>Inicio</th><th>Fin</th>
                    <th class="text-end">Días</th><th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for v in vacaciones %}
                <tr>
                    <td class="fw-semibold">{{ v.apellidos }}, {{ v.nombre }}</td>
                    <td>{{ v.fecha_inicio|date('d/m/Y') }}</td>
                    <td>{{ v.fecha_fin|date('d/m/Y') }}</td>
                    <td class="text-end">{{ v.dias_tomados }}</td>
                    <td class="text-center">
                        <a href="{{ url_for('vacaciones.edit', {'id': v.id_vacacion}) }}" class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-url="{{ url_for('vacaciones.destroy', {'id': v.id_vacacion}) }}"
                                data-info="{{ v.apellidos }}, {{ v.nombre }} — {{ v.fecha_inicio|date('d/m/Y') }}"><i class="bi bi-trash3"></i></button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ vacaciones|length }} registro(s).</div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar las vacaciones de <strong id="modalInfo"></strong>? Se revertirá el saldo.</div>
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
    document.getElementById('formEliminar').action = btn.getAttribute('data-url');
    document.getElementById('modalInfo').textContent = btn.getAttribute('data-info');
});
</script>
{% endblock %}
```

Commit `feat: add vacaciones index template`.

---

## Task 5: form template — `templates/vacaciones/form.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ url_for('vacaciones.index') }}">Vacaciones</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Registrar' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-sun me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('vacaciones.store') %}
                {% else %}
                    {% set form_action = url_for('vacaciones.update', {'id': vacacion.id_vacacion}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    {% if accion == 'crear' %}
                    <div class="mb-3">
                        <label for="id_solicitud" class="form-label fw-semibold">Solicitud aprobada <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_solicitud" name="id_solicitud" required>
                            <option value="">— Seleccione una solicitud —</option>
                            {% for s in solicitudes %}
                            <option value="{{ s.id_solicitud }}" {{ (vacacion.id_solicitud ?? '') == s.id_solicitud ? 'selected' : '' }}>
                                {{ s.apellidos }}, {{ s.nombre }} — {{ s.fecha_inicio|date('d/m/Y') }} a {{ s.fecha_fin ? s.fecha_fin|date('d/m/Y') : '?' }}
                            </option>
                            {% endfor %}
                        </select>
                        <div class="form-text">Solo se listan solicitudes de vacaciones aprobadas sin registro previo.</div>
                    </div>
                    {% else %}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Empleado</label>
                        <input type="text" class="form-control" value="{{ vacacion.apellidos }}, {{ vacacion.nombre }}" disabled>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Inicio</label>
                            <input type="text" class="form-control" value="{{ vacacion.fecha_inicio|date('d/m/Y') }}" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fin</label>
                            <input type="text" class="form-control" value="{{ vacacion.fecha_fin|date('d/m/Y') }}" disabled>
                        </div>
                    </div>
                    {% endif %}

                    <div class="mb-3">
                        <label for="dias_tomados" class="form-label fw-semibold">Días tomados</label>
                        <input type="number" step="0.1" min="0" max="999.9" class="form-control"
                               id="dias_tomados" name="dias_tomados" value="{{ vacacion.dias_tomados ?? '' }}">
                        <div class="form-text">Dejar vacío para usar los días naturales del rango (inclusive).</div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ url_for('vacaciones.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar' : 'Actualizar' }}</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

Commit `feat: add vacaciones form template`.

---

## Task 6: Wire DI + routes + navbar

**dependencies.php** — after Horas Extra entries:
```php
    \App\Repositories\VacacionesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\VacacionesRepository($c->get(PDO::class));
    },
```
```php
    \App\Services\VacacionesService::class => function (ContainerInterface $c) {
        return new \App\Services\VacacionesService(
            $c->get(\App\Repositories\VacacionesRepository::class),
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```
```php
    \App\Controllers\VacacionesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\VacacionesController(
            $c->get(Twig::class),
            $c->get(\App\Services\VacacionesService::class)
        );
    },
```

**routes.php** — add `use App\Controllers\VacacionesController;` and after the `/horas-extra` group:
```php
    // ── Vacaciones (solo admin) ───────────────────────────
    $app->group('/vacaciones', function (RouteCollectorProxy $group) {
        $group->get('',                [VacacionesController::class, 'index'])->setName('vacaciones.index');
        $group->get('/crear',          [VacacionesController::class, 'create'])->setName('vacaciones.create');
        $group->post('/crear',         [VacacionesController::class, 'store'])->setName('vacaciones.store');
        $group->get('/{id}/editar',    [VacacionesController::class, 'edit'])->setName('vacaciones.edit');
        $group->post('/{id}/editar',   [VacacionesController::class, 'update'])->setName('vacaciones.update');
        $group->post('/{id}/eliminar', [VacacionesController::class, 'destroy'])->setName('vacaciones.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

**base.html.twig** — change the "Vacaciones" item in the Operaciones dropdown:
```twig
                        <li><a class="dropdown-item" href="{{ url_for('vacaciones.index') }}"><i class="bi bi-sun me-1"></i>Vacaciones</a></li>
```

Lint both PHP config files. Commit `feat: wire Vacaciones (DI, routes, navbar)`.

---

## Task 7: Integration verification (controller-run)
Boot + routes; confirm vacaciones.* resolve; no path_for/base_url.

## Task 8: Manual smoke test (user)
Approve a vacaciones solicitud; register it (auto days + period), verify saldo_vacaciones row created/updated and the saldo notice flash; edit days (saldo adjusts by delta); delete (saldo reverts); duplicate guard; filters; auditoría.
