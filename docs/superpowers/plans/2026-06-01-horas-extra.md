# Horas Extra Module Implementation Plan

> Use superpowers:subagent-driven-development. Verbatim code below. API: `url_for`/`base_path` in templates, `RouteContext::urlFor` in controller, modal `data-url`. No `path_for`/`base_url`.

**Goal:** Horas Extra CRUD derived from approved `horas_extra` solicitudes; auto period + auto factor (2.00 holiday / 1.50 ordinary), one HE per solicitud, audited, admin-only.

**Schema `horas_extra`:** id_hora_extra PK, id_solicitud FK NOT NULL, id_empleado FK, id_periodo FK, fecha DATE, cantidad_horas DECIMAL(4,2), factor_recargo DECIMAL(3,2) DEFAULT 1.50.

Route name prefix: `horas_extra.*`. Group path `/horas-extra`.

---

## Task 1: HorasExtraRepository — `src/Repositories/HorasExtraRepository.php`

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * HorasExtraRepository — SQL para `horas_extra` y lookups asociados.
 */
class HorasExtraRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT he.id_hora_extra, he.id_solicitud, he.id_empleado, he.id_periodo,
                       he.fecha, he.cantidad_horas, he.factor_recargo, e.nombre, e.apellidos
                  FROM horas_extra he
                  JOIN empleados e ON e.id_empleado = he.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'he.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'he.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY he.fecha DESC, e.apellidos ASC';

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
            "SELECT he.*, e.nombre, e.apellidos
               FROM horas_extra he
               JOIN empleados e ON e.id_empleado = he.id_empleado
              WHERE he.id_hora_extra = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function existsBySolicitud(int $idSolicitud, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT COUNT(*) FROM horas_extra WHERE id_solicitud = :s';
        $params = [':s' => $idSolicitud];
        if ($excludeId !== null) {
            $sql .= ' AND id_hora_extra <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Solicitudes horas_extra aprobadas sin HE asociada (más la actual al editar).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSolicitudesDisponibles(?int $incluirId = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.fecha_inicio, s.horas, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado
             LEFT JOIN horas_extra he ON he.id_solicitud = s.id_solicitud
                 WHERE s.tipo = 'horas_extra' AND s.estado = 'aprobada'
                   AND (he.id_hora_extra IS NULL";
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

    public function esFeriado(string $fecha): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM feriados WHERE fecha = :f');
        $stmt->execute([':f' => $fecha]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO horas_extra (id_solicitud, id_empleado, id_periodo, fecha, cantidad_horas, factor_recargo)
             VALUES (:id_solicitud, :id_empleado, :id_periodo, :fecha, :cantidad_horas, :factor_recargo)'
        );
        $stmt->execute([
            ':id_solicitud'   => $d['id_solicitud'],
            ':id_empleado'    => $d['id_empleado'],
            ':id_periodo'     => $d['id_periodo'],
            ':fecha'          => $d['fecha'],
            ':cantidad_horas' => $d['cantidad_horas'],
            ':factor_recargo' => $d['factor_recargo'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, float $cantidadHoras, float $factorRecargo): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE horas_extra SET cantidad_horas = :c, factor_recargo = :f WHERE id_hora_extra = :id'
        );
        $stmt->execute([':c' => $cantidadHoras, ':f' => $factorRecargo, ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM horas_extra WHERE id_hora_extra = :id');
        $stmt->execute([':id' => $id]);
    }
}
```

Lint, commit `feat: add HorasExtraRepository`.

---

## Task 2: HorasExtraService — `src/Services/HorasExtraService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * HorasExtraService — registro de horas extra derivadas de solicitudes aprobadas.
 */
class HorasExtraService
{
    public function __construct(
        private readonly HorasExtraRepository  $repo,
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
            throw new RuntimeException('Registro de horas extra no encontrado.');
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
            if (($solicitud['tipo'] ?? '') !== 'horas_extra') {
                $errores[] = 'La solicitud seleccionada no es de tipo horas extra.';
            }
            if (($solicitud['estado'] ?? '') !== 'aprobada') {
                $errores[] = 'La solicitud debe estar aprobada.';
            }
            if ($this->repo->existsBySolicitud($idSolicitud)) {
                $errores[] = 'Esta solicitud ya tiene horas extra registradas.';
            }
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fecha      = (string)$solicitud['fecha_inicio'];
        $idEmpleado = (int)$solicitud['id_empleado'];

        $errores  = [];
        $cantidad = $this->resolverCantidad($datos['cantidad_horas'] ?? '', $solicitud['horas'] ?? null, $errores);
        $periodo  = $this->repo->findPeriodoAbiertoPorFecha($fecha);
        if ($periodo === null) {
            $errores[] = 'No hay un periodo de pago abierto que contenga la fecha de la solicitud.';
        }
        $factor = $this->resolverFactor($datos['factor_recargo'] ?? '', $fecha, $errores);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $id = $this->repo->insert([
            'id_solicitud'   => $idSolicitud,
            'id_empleado'    => $idEmpleado,
            'id_periodo'     => (int)$periodo['id_periodo'],
            'fecha'          => $fecha,
            'cantidad_horas' => $cantidad,
            'factor_recargo' => $factor,
        ]);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'horas_extra', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $he       = $this->obtener($id);
        $errores  = [];
        $cantidad = $this->resolverCantidad($datos['cantidad_horas'] ?? '', $he['cantidad_horas'] ?? null, $errores);
        $factor   = $this->resolverFactor($datos['factor_recargo'] ?? '', (string)$he['fecha'], $errores);
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }
        $this->repo->update($id, $cantidad, $factor);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'horas_extra', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'horas_extra', $id, $ip);
    }

    /**
     * @param string[] $errores
     */
    private function resolverCantidad(mixed $raw, mixed $fallback, array &$errores): float
    {
        $valor = null;
        if (is_numeric($raw) && (float)$raw > 0) {
            $valor = round((float)$raw, 2);
        } elseif (is_numeric($fallback) && (float)$fallback > 0) {
            $valor = round((float)$fallback, 2);
        }
        if ($valor === null) {
            $errores[] = 'La cantidad de horas debe ser mayor a 0.';
            return 0.0;
        }
        if ($valor > 99.99) {
            $errores[] = 'La cantidad de horas no puede superar 99.99.';
        }
        return $valor;
    }

    /**
     * @param string[] $errores
     */
    private function resolverFactor(mixed $raw, string $fecha, array &$errores): float
    {
        if (is_numeric($raw)) {
            $f = round((float)$raw, 2);
            if (abs($f - 1.5) < 0.001) {
                return 1.50;
            }
            if (abs($f - 2.0) < 0.001) {
                return 2.00;
            }
            $errores[] = 'El factor de recargo debe ser 1.50 o 2.00.';
            return 1.50;
        }
        return $this->repo->esFeriado($fecha) ? 2.00 : 1.50;
    }
}
```

Lint, commit `feat: add HorasExtraService deriving from approved solicitudes`.

---

## Task 3: HorasExtraController — `src/Controllers/HorasExtraController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HorasExtraService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class HorasExtraController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly HorasExtraService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'horas_extra/index.html.twig', [
            'titulo'         => 'Horas Extra',
            'horasExtra'     => $this->service->listar($idPeriodo, $idEmpleado),
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
        return $this->twig->render($response, 'horas_extra/form.html.twig', [
            'titulo'      => 'Registrar Horas Extra',
            'accion'      => 'crear',
            'horaExtra'   => [],
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
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Horas extra registradas exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'horas_extra/form.html.twig', [
                'titulo'      => 'Registrar Horas Extra',
                'accion'      => 'crear',
                'horaExtra'   => $datos,
                'solicitudes' => $datosForm['solicitudes'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $horaExtra = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Registro de horas extra no encontrado.';
            return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario((int)$horaExtra['id_solicitud']);
        return $this->twig->render($response, 'horas_extra/form.html.twig', [
            'titulo'      => 'Editar Horas Extra',
            'accion'      => 'editar',
            'horaExtra'   => $horaExtra,
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
            $_SESSION['flash_success'] = 'Horas extra actualizadas correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            try {
                $horaExtra = $this->service->obtener($id);
            } catch (RuntimeException) {
                $_SESSION['flash_error'] = 'Registro de horas extra no encontrado.';
                return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
            }
            return $this->twig->render($response->withStatus(422), 'horas_extra/form.html.twig', [
                'titulo'      => 'Editar Horas Extra',
                'accion'      => 'editar',
                'horaExtra'   => array_merge($horaExtra, $datos),
                'solicitudes' => [],
                'errores'     => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Registro de horas extra eliminado.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'horas_extra.index'))->withStatus(302);
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

Lint, commit `feat: add HorasExtraController`.

---

## Task 4: index template — `templates/horas_extra/index.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Horas Extra{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Operaciones</li>
        <li class="breadcrumb-item active">Horas Extra</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-alarm me-2 text-primary"></i>Horas Extra</h2>
    <a href="{{ url_for('horas_extra.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Registrar Horas Extra
    </a>
</div>

<form method="GET" action="{{ url_for('horas_extra.index') }}" class="row g-2 mb-3">
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

{% if horasExtra|length == 0 %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay registros de horas extra.</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th><th>Fecha</th><th class="text-end">Horas</th>
                    <th class="text-center">Factor</th><th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for h in horasExtra %}
                <tr>
                    <td class="fw-semibold">{{ h.apellidos }}, {{ h.nombre }}</td>
                    <td>{{ h.fecha|date('d/m/Y') }}</td>
                    <td class="text-end">{{ h.cantidad_horas }}</td>
                    <td class="text-center">
                        {{ h.factor_recargo }}×
                        {% if h.factor_recargo >= 2 %}<span class="badge bg-warning text-dark ms-1">Feriado</span>{% endif %}
                    </td>
                    <td class="text-center">
                        <a href="{{ url_for('horas_extra.edit', {'id': h.id_hora_extra}) }}" class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-url="{{ url_for('horas_extra.destroy', {'id': h.id_hora_extra}) }}"
                                data-info="{{ h.apellidos }}, {{ h.nombre }} — {{ h.fecha|date('d/m/Y') }}"><i class="bi bi-trash3"></i></button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ horasExtra|length }} registro(s).</div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar las horas extra de <strong id="modalInfo"></strong>?</div>
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

Commit `feat: add horas_extra index template`.

---

## Task 5: form template — `templates/horas_extra/form.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ url_for('horas_extra.index') }}">Horas Extra</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Registrar' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-alarm me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('horas_extra.store') %}
                {% else %}
                    {% set form_action = url_for('horas_extra.update', {'id': horaExtra.id_hora_extra}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    {% if accion == 'crear' %}
                    <div class="mb-3">
                        <label for="id_solicitud" class="form-label fw-semibold">Solicitud aprobada <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_solicitud" name="id_solicitud" required>
                            <option value="">— Seleccione una solicitud —</option>
                            {% for s in solicitudes %}
                            <option value="{{ s.id_solicitud }}" {{ (horaExtra.id_solicitud ?? '') == s.id_solicitud ? 'selected' : '' }}>
                                {{ s.apellidos }}, {{ s.nombre }} — {{ s.fecha_inicio|date('d/m/Y') }} — {{ s.horas }}h
                            </option>
                            {% endfor %}
                        </select>
                        <div class="form-text">Solo se listan solicitudes de horas extra aprobadas sin registro previo.</div>
                    </div>
                    {% else %}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Empleado</label>
                        <input type="text" class="form-control" value="{{ horaExtra.apellidos }}, {{ horaExtra.nombre }}" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fecha</label>
                        <input type="text" class="form-control" value="{{ horaExtra.fecha|date('d/m/Y') }}" disabled>
                    </div>
                    {% endif %}

                    <div class="mb-3">
                        <label for="cantidad_horas" class="form-label fw-semibold">Cantidad de horas</label>
                        <input type="number" step="0.01" min="0" max="99.99" class="form-control"
                               id="cantidad_horas" name="cantidad_horas" value="{{ horaExtra.cantidad_horas ?? '' }}">
                        {% if accion == 'crear' %}<div class="form-text">Dejar vacío para usar las horas de la solicitud.</div>{% endif %}
                    </div>

                    <div class="mb-3">
                        <label for="factor_recargo" class="form-label fw-semibold">Factor de recargo</label>
                        <select class="form-select" id="factor_recargo" name="factor_recargo">
                            <option value="" {{ (horaExtra.factor_recargo ?? '') == '' ? 'selected' : '' }}>Automático (1.50 ordinaria / 2.00 feriado)</option>
                            <option value="1.50" {{ (horaExtra.factor_recargo ?? '') in ['1.50', '1.5'] ? 'selected' : '' }}>1.50 (ordinaria)</option>
                            <option value="2.00" {{ (horaExtra.factor_recargo ?? '') in ['2.00', '2.0', '2'] ? 'selected' : '' }}>2.00 (feriado)</option>
                        </select>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ url_for('horas_extra.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar' : 'Actualizar' }}</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

Commit `feat: add horas_extra form template`.

---

## Task 6: Wire DI + routes + navbar

**dependencies.php** — after Solicitudes entries:
```php
    \App\Repositories\HorasExtraRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\HorasExtraRepository($c->get(PDO::class));
    },
```
```php
    \App\Services\HorasExtraService::class => function (ContainerInterface $c) {
        return new \App\Services\HorasExtraService(
            $c->get(\App\Repositories\HorasExtraRepository::class),
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```
```php
    \App\Controllers\HorasExtraController::class => function (ContainerInterface $c) {
        return new \App\Controllers\HorasExtraController(
            $c->get(Twig::class),
            $c->get(\App\Services\HorasExtraService::class)
        );
    },
```

**routes.php** — add `use App\Controllers\HorasExtraController;` and after the `/solicitudes` group:
```php
    // ── Horas Extra (solo admin) ──────────────────────────
    $app->group('/horas-extra', function (RouteCollectorProxy $group) {
        $group->get('',                [HorasExtraController::class, 'index'])->setName('horas_extra.index');
        $group->get('/crear',          [HorasExtraController::class, 'create'])->setName('horas_extra.create');
        $group->post('/crear',         [HorasExtraController::class, 'store'])->setName('horas_extra.store');
        $group->get('/{id}/editar',    [HorasExtraController::class, 'edit'])->setName('horas_extra.edit');
        $group->post('/{id}/editar',   [HorasExtraController::class, 'update'])->setName('horas_extra.update');
        $group->post('/{id}/eliminar', [HorasExtraController::class, 'destroy'])->setName('horas_extra.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

**base.html.twig** — in the Operaciones dropdown, change the "Horas Extra" item:
```twig
                        <li><a class="dropdown-item" href="{{ url_for('horas_extra.index') }}"><i class="bi bi-alarm me-1"></i>Horas Extra</a></li>
```

Lint both PHP config files. Commit `feat: wire Horas Extra (DI, routes, navbar)`.

---

## Task 7: Integration verification (controller-run)
Boot + routes; confirm horas_extra.index/create/store/edit/update/destroy resolve; no path_for/base_url.

## Task 8: Manual smoke test (user)
Approve a horas_extra solicitud first; register HE from it (auto period + auto factor), verify duplicate guard, override cantidad/factor, edit, delete, filters, auditoría.
