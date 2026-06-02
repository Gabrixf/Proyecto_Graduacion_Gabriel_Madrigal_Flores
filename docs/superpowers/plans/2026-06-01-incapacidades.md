# Incapacidades Module Implementation Plan

> Use superpowers:subagent-driven-development. Verbatim code. API: `url_for`/`base_path`, `RouteContext::urlFor`, modal `data-url`. No `path_for`/`base_url`.

**Goal:** Incapacidades CRUD (CCSS/INS/particular), auto period from fecha_inicio, dias = calendar days inclusive with override, documento_respaldo as text reference, audited, admin-only.

**Schema `incapacidades`:** id_incapacidad PK, id_empleado FK, id_periodo FK, tipo ENUM('CCSS','INS','particular'), fecha_inicio DATE, fecha_fin DATE, dias INT, documento_respaldo VARCHAR(255) NULL.

Route prefix `incapacidades.*`, group `/incapacidades`.

---

## Task 1: IncapacidadesRepository — `src/Repositories/IncapacidadesRepository.php`

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * IncapacidadesRepository — SQL para `incapacidades`. Sin lógica de negocio.
 */
class IncapacidadesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $tipo = null): array
    {
        $sql = "SELECT i.id_incapacidad, i.id_empleado, i.id_periodo, i.tipo,
                       i.fecha_inicio, i.fecha_fin, i.dias, i.documento_respaldo,
                       e.nombre, e.apellidos
                  FROM incapacidades i
                  JOIN empleados e ON e.id_empleado = i.id_empleado";
        $where  = [];
        $params = [];
        if ($idPeriodo !== null) {
            $where[] = 'i.id_periodo = :periodo';
            $params[':periodo'] = $idPeriodo;
        }
        if ($idEmpleado !== null) {
            $where[] = 'i.id_empleado = :empleado';
            $params[':empleado'] = $idEmpleado;
        }
        if ($tipo !== null) {
            $where[] = 'i.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY i.fecha_inicio DESC, e.apellidos ASC';

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
            "SELECT i.*, e.nombre, e.apellidos
               FROM incapacidades i
               JOIN empleados e ON e.id_empleado = i.id_empleado
              WHERE i.id_incapacidad = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
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
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO incapacidades (id_empleado, id_periodo, tipo, fecha_inicio, fecha_fin, dias, documento_respaldo)
             VALUES (:id_empleado, :id_periodo, :tipo, :fecha_inicio, :fecha_fin, :dias, :documento_respaldo)'
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
            'UPDATE incapacidades SET
                id_empleado = :id_empleado, id_periodo = :id_periodo, tipo = :tipo,
                fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin, dias = :dias,
                documento_respaldo = :documento_respaldo
              WHERE id_incapacidad = :id'
        );
        $params = $this->bind($d);
        $params[':id'] = $id;
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM incapacidades WHERE id_incapacidad = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function bind(array $d): array
    {
        return [
            ':id_empleado'        => $d['id_empleado'],
            ':id_periodo'         => $d['id_periodo'],
            ':tipo'               => $d['tipo'],
            ':fecha_inicio'       => $d['fecha_inicio'],
            ':fecha_fin'          => $d['fecha_fin'],
            ':dias'               => $d['dias'],
            ':documento_respaldo' => $d['documento_respaldo'],
        ];
    }
}
```

Lint, commit `feat: add IncapacidadesRepository`.

---

## Task 2: IncapacidadesService — `src/Services/IncapacidadesService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\IncapacidadesRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * IncapacidadesService — lógica de negocio de incapacidades médicas.
 */
class IncapacidadesService
{
    private const TIPOS = ['CCSS', 'INS', 'particular'];

    public function __construct(
        private readonly IncapacidadesRepository $repo,
        private readonly EmpleadosRepository     $empleadosRepo,
        private readonly PeriodosRepository      $periodosRepo,
        private readonly AuditoriaRepository     $auditoriaRepo
    ) {}

    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $tipo = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado, $tipo);
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Incapacidad no encontrada.');
        }
        return $row;
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>}
     */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->empleadosRepo->findAll('activo')];
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
        $fila = $this->validar($datos);
        try {
            $id = $this->repo->insert($fila);
        } catch (PDOException $e) {
            throw new InvalidArgumentException('No se pudo guardar la incapacidad: ' . $e->getMessage());
        }
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'incapacidades', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $fila = $this->validar($datos);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'incapacidades', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'incapacidades', $id, $ip);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d): array
    {
        $errores = [];

        $idEmpleado = isset($d['id_empleado']) && is_numeric($d['id_empleado']) ? (int)$d['id_empleado'] : 0;
        $empleado   = $idEmpleado > 0 ? $this->empleadosRepo->findById($idEmpleado) : null;
        if ($empleado === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        } elseif (($empleado['estado'] ?? '') !== 'activo') {
            $errores[] = 'El empleado seleccionado no está activo.';
        }

        $tipo = trim((string)($d['tipo'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Debe seleccionar un tipo de incapacidad válido.';
        }

        $fechaInicio = trim((string)($d['fecha_inicio'] ?? ''));
        $fechaFin    = trim((string)($d['fecha_fin'] ?? ''));
        $iniObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        $finObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);
        if ($iniObj === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }
        if ($finObj === false) {
            $errores[] = 'La fecha de fin es obligatoria y debe tener formato válido.';
        }
        if ($iniObj !== false && $finObj !== false && $finObj < $iniObj) {
            $errores[] = 'La fecha de fin no puede ser anterior a la de inicio.';
        }

        // dias: override entero > 0, o días naturales del rango (inclusive).
        $dias    = 0;
        $diasRaw = $d['dias'] ?? '';
        if (is_numeric($diasRaw) && (int)$diasRaw > 0) {
            $dias = (int)$diasRaw;
        } elseif ($iniObj !== false && $finObj !== false && $finObj >= $iniObj) {
            $dias = $finObj->diff($iniObj)->days + 1;
        }
        if ($dias <= 0) {
            $errores[] = 'Los días deben ser un número entero mayor a 0.';
        }

        // Periodo abierto que contiene la fecha de inicio.
        $idPeriodo = null;
        if ($iniObj !== false) {
            $periodo = $this->repo->findPeriodoAbiertoPorFecha($fechaInicio);
            if ($periodo === null) {
                $errores[] = 'No hay un periodo de pago abierto que contenga la fecha de inicio.';
            } else {
                $idPeriodo = (int)$periodo['id_periodo'];
            }
        }

        $documento = trim((string)($d['documento_respaldo'] ?? ''));
        if (mb_strlen($documento) > 255) {
            $errores[] = 'La referencia del documento no puede superar 255 caracteres.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        return [
            'id_empleado'        => $idEmpleado,
            'id_periodo'         => $idPeriodo,
            'tipo'               => $tipo,
            'fecha_inicio'       => $fechaInicio,
            'fecha_fin'          => $fechaFin,
            'dias'               => $dias,
            'documento_respaldo' => $documento !== '' ? $documento : null,
        ];
    }
}
```

Lint, commit `feat: add IncapacidadesService with period resolution and day calc`.

---

## Task 3: IncapacidadesController — `src/Controllers/IncapacidadesController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\IncapacidadesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class IncapacidadesController
{
    public function __construct(
        private readonly Twig                 $twig,
        private readonly IncapacidadesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $idPeriodo  = isset($params['periodo'])  && is_numeric($params['periodo'])  ? (int)$params['periodo']  : null;
        $idEmpleado = isset($params['empleado']) && is_numeric($params['empleado']) ? (int)$params['empleado'] : null;
        $tipo       = in_array($params['tipo'] ?? '', ['CCSS', 'INS', 'particular'], true) ? $params['tipo'] : null;
        $filtros    = $this->service->datosFiltros();

        return $this->twig->render($response, 'incapacidades/index.html.twig', [
            'titulo'         => 'Incapacidades',
            'incapacidades'  => $this->service->listar($idPeriodo, $idEmpleado, $tipo),
            'empleados'      => $filtros['empleados'],
            'periodos'       => $filtros['periodos'],
            'filtroPeriodo'  => $idPeriodo,
            'filtroEmpleado' => $idEmpleado,
            'filtroTipo'     => $tipo,
            'flashSuccess'   => $this->consumeFlash('flash_success'),
            'flashError'     => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'incapacidades/form.html.twig', [
            'titulo'       => 'Registrar Incapacidad',
            'accion'       => 'crear',
            'incapacidad'  => [],
            'empleados'    => $datosForm['empleados'],
            'errores'      => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Incapacidad registrada exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'incapacidades/form.html.twig', [
                'titulo'      => 'Registrar Incapacidad',
                'accion'      => 'crear',
                'incapacidad' => $datos,
                'empleados'   => $datosForm['empleados'],
                'errores'     => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $incapacidad = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Incapacidad no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'incapacidades/form.html.twig', [
            'titulo'      => 'Editar Incapacidad',
            'accion'      => 'editar',
            'incapacidad' => $incapacidad,
            'empleados'   => $datosForm['empleados'],
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
            $_SESSION['flash_success'] = 'Incapacidad actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'incapacidades/form.html.twig', [
                'titulo'      => 'Editar Incapacidad',
                'accion'      => 'editar',
                'incapacidad' => array_merge(['id_incapacidad' => $id], $datos),
                'empleados'   => $datosForm['empleados'],
                'errores'     => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Incapacidad eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'incapacidades.index'))->withStatus(302);
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

Lint, commit `feat: add IncapacidadesController`.

---

## Task 4: index template — `templates/incapacidades/index.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Incapacidades{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Operaciones</li>
        <li class="breadcrumb-item active">Incapacidades</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-bandaid me-2 text-primary"></i>Incapacidades</h2>
    <a href="{{ url_for('incapacidades.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Registrar Incapacidad
    </a>
</div>

<form method="GET" action="{{ url_for('incapacidades.index') }}" class="row g-2 mb-3">
    <div class="col-md-4">
        <select name="periodo" class="form-select form-select-sm">
            <option value="">— Todos los periodos —</option>
            {% for p in periodos %}
            <option value="{{ p.id_periodo }}" {{ filtroPeriodo == p.id_periodo ? 'selected' : '' }}>
                {{ p.fecha_inicio|date('d/m/Y') }} – {{ p.fecha_fin|date('d/m/Y') }} ({{ p.estado }})
            </option>
            {% endfor %}
        </select>
    </div>
    <div class="col-md-4">
        <select name="empleado" class="form-select form-select-sm">
            <option value="">— Todos los empleados —</option>
            {% for e in empleados %}
            <option value="{{ e.id_empleado }}" {{ filtroEmpleado == e.id_empleado ? 'selected' : '' }}>{{ e.apellidos }}, {{ e.nombre }}</option>
            {% endfor %}
        </select>
    </div>
    <div class="col-md-2">
        <select name="tipo" class="form-select form-select-sm">
            <option value="">— Tipo —</option>
            <option value="CCSS"       {{ filtroTipo == 'CCSS'       ? 'selected' : '' }}>CCSS</option>
            <option value="INS"        {{ filtroTipo == 'INS'        ? 'selected' : '' }}>INS</option>
            <option value="particular" {{ filtroTipo == 'particular' ? 'selected' : '' }}>Particular</option>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </div>
</form>

{% if incapacidades|length == 0 %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay incapacidades registradas.</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th><th>Tipo</th><th>Inicio</th><th>Fin</th>
                    <th class="text-end">Días</th><th>Doc.</th><th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for i in incapacidades %}
                <tr>
                    <td class="fw-semibold">{{ i.apellidos }}, {{ i.nombre }}</td>
                    <td>
                        {% if i.tipo == 'CCSS' %}<span class="badge bg-primary">CCSS</span>
                        {% elseif i.tipo == 'INS' %}<span class="badge bg-info text-dark">INS</span>
                        {% else %}<span class="badge bg-secondary">Particular</span>{% endif %}
                    </td>
                    <td>{{ i.fecha_inicio|date('d/m/Y') }}</td>
                    <td>{{ i.fecha_fin|date('d/m/Y') }}</td>
                    <td class="text-end">{{ i.dias }}</td>
                    <td>{% if i.documento_respaldo %}<i class="bi bi-paperclip" title="{{ i.documento_respaldo }}"></i>{% else %}—{% endif %}</td>
                    <td class="text-center">
                        <a href="{{ url_for('incapacidades.edit', {'id': i.id_incapacidad}) }}" class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil-square"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-url="{{ url_for('incapacidades.destroy', {'id': i.id_incapacidad}) }}"
                                data-info="{{ i.apellidos }}, {{ i.nombre }} — {{ i.fecha_inicio|date('d/m/Y') }}"><i class="bi bi-trash3"></i></button>
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ incapacidades|length }} registro(s).</div>
</div>
{% endif %}

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar la incapacidad de <strong id="modalInfo"></strong>?</div>
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

Commit `feat: add incapacidades index template`.

---

## Task 5: form template — `templates/incapacidades/form.html.twig`

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ url_for('incapacidades.index') }}">Incapacidades</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Registrar' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-bandaid me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('incapacidades.store') %}
                {% else %}
                    {% set form_action = url_for('incapacidades.update', {'id': incapacidad.id_incapacidad}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="id_empleado" class="form-label fw-semibold">Empleado <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_empleado" name="id_empleado" required>
                            <option value="">— Seleccione —</option>
                            {% for e in empleados %}
                            <option value="{{ e.id_empleado }}" {{ (incapacidad.id_empleado ?? '') == e.id_empleado ? 'selected' : '' }}>{{ e.apellidos }}, {{ e.nombre }}</option>
                            {% endfor %}
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="tipo" class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
                        <select class="form-select" id="tipo" name="tipo" required>
                            <option value="">— Seleccione —</option>
                            <option value="CCSS"       {{ (incapacidad.tipo ?? '') == 'CCSS'       ? 'selected' : '' }}>CCSS</option>
                            <option value="INS"        {{ (incapacidad.tipo ?? '') == 'INS'        ? 'selected' : '' }}>INS</option>
                            <option value="particular" {{ (incapacidad.tipo ?? '') == 'particular' ? 'selected' : '' }}>Particular</option>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="fecha_inicio" class="form-label fw-semibold">Fecha de inicio <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required value="{{ incapacidad.fecha_inicio ?? '' }}">
                        </div>
                        <div class="col-md-6">
                            <label for="fecha_fin" class="form-label fw-semibold">Fecha de fin <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" required value="{{ incapacidad.fecha_fin ?? '' }}">
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label for="dias" class="form-label fw-semibold">Días</label>
                        <input type="number" step="1" min="0" class="form-control" id="dias" name="dias" value="{{ incapacidad.dias ?? '' }}">
                        <div class="form-text">Dejar vacío para usar los días naturales del rango (inclusive).</div>
                    </div>

                    <div class="mb-3">
                        <label for="documento_respaldo" class="form-label fw-semibold">Documento de respaldo</label>
                        <input type="text" class="form-control" id="documento_respaldo" name="documento_respaldo" maxlength="255" value="{{ incapacidad.documento_respaldo ?? '' }}">
                        <div class="form-text">Referencia o ruta de la boleta médica (opcional).</div>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ url_for('incapacidades.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar' : 'Actualizar' }}</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

Commit `feat: add incapacidades form template`.

---

## Task 6: Wire DI + routes + navbar

**dependencies.php** — after Vacaciones entries:
```php
    \App\Repositories\IncapacidadesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\IncapacidadesRepository($c->get(PDO::class));
    },
```
```php
    \App\Services\IncapacidadesService::class => function (ContainerInterface $c) {
        return new \App\Services\IncapacidadesService(
            $c->get(\App\Repositories\IncapacidadesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\PeriodosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```
```php
    \App\Controllers\IncapacidadesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\IncapacidadesController(
            $c->get(Twig::class),
            $c->get(\App\Services\IncapacidadesService::class)
        );
    },
```

**routes.php** — add `use App\Controllers\IncapacidadesController;` and after the `/vacaciones` group:
```php
    // ── Incapacidades (solo admin) ────────────────────────
    $app->group('/incapacidades', function (RouteCollectorProxy $group) {
        $group->get('',                [IncapacidadesController::class, 'index'])->setName('incapacidades.index');
        $group->get('/crear',          [IncapacidadesController::class, 'create'])->setName('incapacidades.create');
        $group->post('/crear',         [IncapacidadesController::class, 'store'])->setName('incapacidades.store');
        $group->get('/{id}/editar',    [IncapacidadesController::class, 'edit'])->setName('incapacidades.edit');
        $group->post('/{id}/editar',   [IncapacidadesController::class, 'update'])->setName('incapacidades.update');
        $group->post('/{id}/eliminar', [IncapacidadesController::class, 'destroy'])->setName('incapacidades.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

**base.html.twig** — change the "Incapacidades" item in the Operaciones dropdown:
```twig
                        <li><a class="dropdown-item" href="{{ url_for('incapacidades.index') }}"><i class="bi bi-bandaid me-1"></i>Incapacidades</a></li>
```

Lint both PHP config files. Commit `feat: wire Incapacidades (DI, routes, navbar)`.

---

## Task 7: Integration verification (controller-run)
Boot + routes; confirm incapacidades.* resolve; no path_for/base_url.

## Task 8: Manual smoke test (user)
Register an incapacidad (auto period + auto days), validation (future-proof: invalid range, no open period), tipo filter, edit, delete, auditoría.
