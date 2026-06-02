# Solicitudes Module Implementation Plan

> Use superpowers:subagent-driven-development. Verbatim code below.

**Goal:** Unified Solicitudes CRUD + approve/reject workflow (tipo horas_extra/vacaciones/permiso), admin-only, audited. Prerequisite for Horas Extra and Vacaciones.

**API:** templates `url_for`/`base_path`; controller `RouteContext::urlFor`; modals use `data-url`. No `path_for`/`base_url`.

**Schema `solicitudes`:** id_solicitud PK, id_empleado FK, tipo ENUM(horas_extra,vacaciones,permiso), fecha_inicio DATE, fecha_fin DATE NULL, horas DECIMAL(5,2) NULL, motivo VARCHAR(255) NULL, estado ENUM(pendiente,aprobada,rechazada) DEFAULT pendiente, fecha_solicitud TS, fecha_resolucion TS NULL, id_usuario_resuelve FK NULL, observacion_admin VARCHAR(255) NULL. Dependent tables `horas_extra`,`vacaciones`,`permisos` each have `id_solicitud` NOT NULL FK.

---

## Task 1: SolicitudesRepository

Create `src/Repositories/SolicitudesRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * SolicitudesRepository — SQL para la tabla `solicitudes`. Sin lógica de negocio.
 */
class SolicitudesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(?string $tipo = null, ?string $estado = null): array
    {
        $sql = "SELECT s.id_solicitud, s.id_empleado, s.tipo, s.fecha_inicio, s.fecha_fin,
                       s.horas, s.motivo, s.estado, s.fecha_solicitud, s.fecha_resolucion,
                       s.observacion_admin, e.nombre, e.apellidos
                  FROM solicitudes s
                  JOIN empleados e ON e.id_empleado = s.id_empleado";
        $where  = [];
        $params = [];
        if ($tipo !== null) {
            $where[] = 's.tipo = :tipo';
            $params[':tipo'] = $tipo;
        }
        if ($estado !== null) {
            $where[] = 's.estado = :estado';
            $params[':estado'] = $estado;
        }
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY s.fecha_solicitud DESC';

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
            "SELECT s.*, e.nombre, e.apellidos
               FROM solicitudes s
               JOIN empleados e ON e.id_empleado = s.id_empleado
              WHERE s.id_solicitud = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO solicitudes (id_empleado, tipo, fecha_inicio, fecha_fin, horas, motivo)
             VALUES (:id_empleado, :tipo, :fecha_inicio, :fecha_fin, :horas, :motivo)'
        );
        $stmt->execute([
            ':id_empleado'  => $d['id_empleado'],
            ':tipo'         => $d['tipo'],
            ':fecha_inicio' => $d['fecha_inicio'],
            ':fecha_fin'    => $d['fecha_fin'],
            ':horas'        => $d['horas'],
            ':motivo'       => $d['motivo'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $d
     */
    public function update(int $id, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE solicitudes SET
                tipo = :tipo, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin,
                horas = :horas, motivo = :motivo
              WHERE id_solicitud = :id'
        );
        $stmt->execute([
            ':tipo'         => $d['tipo'],
            ':fecha_inicio' => $d['fecha_inicio'],
            ':fecha_fin'    => $d['fecha_fin'],
            ':horas'        => $d['horas'],
            ':motivo'       => $d['motivo'],
            ':id'           => $id,
        ]);
    }

    public function resolver(int $id, string $estado, int $idUsuario, ?string $obs): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE solicitudes SET
                estado = :estado, fecha_resolucion = NOW(),
                id_usuario_resuelve = :usuario, observacion_admin = :obs
              WHERE id_solicitud = :id'
        );
        $stmt->execute([
            ':estado'  => $estado,
            ':usuario' => $idUsuario,
            ':obs'     => $obs,
            ':id'      => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM solicitudes WHERE id_solicitud = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Cuenta registros derivados (HE/vacaciones/permisos) que referencian la solicitud.
     */
    public function countDependientes(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM horas_extra WHERE id_solicitud = :id1) +
                (SELECT COUNT(*) FROM vacaciones  WHERE id_solicitud = :id2) +
                (SELECT COUNT(*) FROM permisos    WHERE id_solicitud = :id3) AS total'
        );
        $stmt->execute([':id1' => $id, ':id2' => $id, ':id3' => $id]);
        return (int)$stmt->fetchColumn();
    }
}
```

Lint, then commit `feat: add SolicitudesRepository`.

---

## Task 2: SolicitudesService

Create `src/Services/SolicitudesService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * SolicitudesService — registro y resolución (aprobar/rechazar) de solicitudes.
 * No accede a PDO ni a $_SESSION/$_SERVER.
 */
class SolicitudesService
{
    private const TIPOS = ['horas_extra', 'vacaciones', 'permiso'];

    public function __construct(
        private readonly SolicitudesRepository $repo,
        private readonly EmpleadosRepository   $empleadosRepo,
        private readonly AuditoriaRepository   $auditoriaRepo
    ) {}

    public function listar(?string $tipo = null, ?string $estado = null): array
    {
        return $this->repo->findAll($tipo, $estado);
    }

    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Solicitud no encontrada.');
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

    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $fila = $this->validar($datos);
        $id = $this->repo->insert($fila);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'solicitudes', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('No se puede editar una solicitud ya resuelta.');
        }
        $fila = $this->validar($datos);
        $this->repo->update($id, $fila);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    public function aprobar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        $this->resolverEstado($id, 'aprobada', $loggedInId, $ip, $obs);
    }

    public function rechazar(int $id, int $loggedInId, string $ip, ?string $obs): void
    {
        $this->resolverEstado($id, 'rechazada', $loggedInId, $ip, $obs);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') === 'aprobada') {
            throw new RuntimeException('No se puede eliminar una solicitud aprobada.');
        }
        if ($this->repo->countDependientes($id) > 0) {
            throw new RuntimeException('No se puede eliminar: la solicitud tiene registros asociados.');
        }
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'solicitudes', $id, $ip);
    }

    private function resolverEstado(int $id, string $estado, int $loggedInId, string $ip, ?string $obs): void
    {
        $actual = $this->obtener($id);
        if (($actual['estado'] ?? '') !== 'pendiente') {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }
        $obs = ($obs !== null && trim($obs) !== '') ? mb_substr(trim($obs), 0, 255) : null;
        $this->repo->resolver($id, $estado, $loggedInId, $obs);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'solicitudes', $id, $ip);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d): array
    {
        $errores = [];

        $tipo = trim((string)($d['tipo'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Debe seleccionar un tipo de solicitud válido.';
        }

        $idEmpleado = isset($d['id_empleado']) && is_numeric($d['id_empleado']) ? (int)$d['id_empleado'] : 0;
        $empleado   = $idEmpleado > 0 ? $this->empleadosRepo->findById($idEmpleado) : null;
        if ($empleado === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        } elseif (($empleado['estado'] ?? '') !== 'activo') {
            $errores[] = 'El empleado seleccionado no está activo.';
        }

        $fechaInicio = trim((string)($d['fecha_inicio'] ?? ''));
        $iniObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio);
        if ($iniObj === false) {
            $errores[] = 'La fecha de inicio es obligatoria y debe tener formato válido.';
        }

        $fechaFin   = trim((string)($d['fecha_fin'] ?? ''));
        $horasRaw   = $d['horas'] ?? '';
        $finValor   = null;
        $horasValor = null;

        if ($tipo === 'horas_extra') {
            if (!is_numeric($horasRaw) || (float)$horasRaw <= 0 || (float)$horasRaw > 999.99) {
                $errores[] = 'Para horas extra, las horas deben ser un número mayor a 0.';
            } else {
                $horasValor = round((float)$horasRaw, 2);
            }
            // Día único: la fecha de fin es la misma de inicio.
            $finValor = $iniObj !== false ? $fechaInicio : null;
        } elseif ($tipo === 'vacaciones' || $tipo === 'permiso') {
            $finObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaFin);
            if ($finObj === false) {
                $errores[] = 'La fecha de fin es obligatoria para vacaciones y permisos.';
            } elseif ($iniObj !== false && $finObj < $iniObj) {
                $errores[] = 'La fecha de fin no puede ser anterior a la de inicio.';
            } else {
                $finValor = $fechaFin;
            }
        }

        $motivo = trim((string)($d['motivo'] ?? ''));
        if (mb_strlen($motivo) > 255) {
            $errores[] = 'El motivo no puede superar 255 caracteres.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        return [
            'id_empleado'  => $idEmpleado,
            'tipo'         => $tipo,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $finValor,
            'horas'        => $horasValor,
            'motivo'       => $motivo !== '' ? $motivo : null,
        ];
    }
}
```

Lint, then commit `feat: add SolicitudesService with per-type validation and resolve workflow`.

---

## Task 3: SolicitudesController

Create `src/Controllers/SolicitudesController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SolicitudesService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

class SolicitudesController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly SolicitudesService $service
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $tipo   = in_array($params['tipo']   ?? '', ['horas_extra', 'vacaciones', 'permiso'], true) ? $params['tipo']   : null;
        $estado = in_array($params['estado'] ?? '', ['pendiente', 'aprobada', 'rechazada'], true)    ? $params['estado'] : null;

        return $this->twig->render($response, 'solicitudes/index.html.twig', [
            'titulo'       => 'Solicitudes',
            'solicitudes'  => $this->service->listar($tipo, $estado),
            'filtroTipo'   => $tipo,
            'filtroEstado' => $estado,
            'flashSuccess' => $this->consumeFlash('flash_success'),
            'flashError'   => $this->consumeFlash('flash_error'),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'solicitudes/form.html.twig', [
            'titulo'    => 'Nueva Solicitud',
            'accion'    => 'crear',
            'solicitud' => [],
            'empleados' => $datosForm['empleados'],
            'errores'   => [],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $datos      = (array)$request->getParsedBody();
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->crear($datos, $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud registrada exitosamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'solicitudes/form.html.twig', [
                'titulo'    => 'Nueva Solicitud',
                'accion'    => 'crear',
                'solicitud' => $datos,
                'empleados' => $datosForm['empleados'],
                'errores'   => [$e->getMessage()],
            ]);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        try {
            $solicitud = $this->service->obtener((int)$args['id']);
        } catch (RuntimeException) {
            $_SESSION['flash_error'] = 'Solicitud no encontrada.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        }
        if (($solicitud['estado'] ?? '') !== 'pendiente') {
            $_SESSION['flash_error'] = 'No se puede editar una solicitud ya resuelta.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        }
        $datosForm = $this->service->datosFormulario();
        return $this->twig->render($response, 'solicitudes/form.html.twig', [
            'titulo'    => 'Editar Solicitud',
            'accion'    => 'editar',
            'solicitud' => $solicitud,
            'empleados' => $datosForm['empleados'],
            'errores'   => [],
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
            $_SESSION['flash_success'] = 'Solicitud actualizada correctamente.';
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        } catch (InvalidArgumentException $e) {
            $datosForm = $this->service->datosFormulario();
            return $this->twig->render($response->withStatus(422), 'solicitudes/form.html.twig', [
                'titulo'    => 'Editar Solicitud',
                'accion'    => 'editar',
                'solicitud' => array_merge(['id_solicitud' => $id], $datos),
                'empleados' => $datosForm['empleados'],
                'errores'   => [$e->getMessage()],
            ]);
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
        }
    }

    public function aprobar(Request $request, Response $response, array $args): Response
    {
        $this->resolverAccion($request, $args, true);
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    public function rechazar(Request $request, Response $response, array $args): Response
    {
        $this->resolverAccion($request, $args, false);
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $this->service->eliminar((int)$args['id'], $loggedInId, $ip);
            $_SESSION['flash_success'] = 'Solicitud eliminada.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        return $response->withHeader('Location', $this->urlFor($request, 'solicitudes.index'))->withStatus(302);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function resolverAccion(Request $request, array $args, bool $aprobar): void
    {
        $loggedInId = (int)$_SESSION['usuario_id'];
        $ip         = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $obs        = (string)(((array)$request->getParsedBody())['observacion'] ?? '');
        try {
            if ($aprobar) {
                $this->service->aprobar((int)$args['id'], $loggedInId, $ip, $obs);
                $_SESSION['flash_success'] = 'Solicitud aprobada.';
            } else {
                $this->service->rechazar((int)$args['id'], $loggedInId, $ip, $obs);
                $_SESSION['flash_success'] = 'Solicitud rechazada.';
            }
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
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

Lint, then commit `feat: add SolicitudesController with approve/reject actions`.

---

## Task 4: index template

Create `templates/solicitudes/index.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}Solicitudes{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item">Operaciones</li>
        <li class="breadcrumb-item active">Solicitudes</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-inbox me-2 text-primary"></i>Solicitudes</h2>
    <a href="{{ url_for('solicitudes.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Nueva Solicitud
    </a>
</div>

<form method="GET" action="{{ url_for('solicitudes.index') }}" class="row g-2 mb-3">
    <div class="col-md-4">
        <select name="tipo" class="form-select form-select-sm">
            <option value="">— Todos los tipos —</option>
            <option value="horas_extra" {{ filtroTipo == 'horas_extra' ? 'selected' : '' }}>Horas extra</option>
            <option value="vacaciones"  {{ filtroTipo == 'vacaciones'  ? 'selected' : '' }}>Vacaciones</option>
            <option value="permiso"     {{ filtroTipo == 'permiso'     ? 'selected' : '' }}>Permiso</option>
        </select>
    </div>
    <div class="col-md-4">
        <select name="estado" class="form-select form-select-sm">
            <option value="">— Todos los estados —</option>
            <option value="pendiente" {{ filtroEstado == 'pendiente' ? 'selected' : '' }}>Pendiente</option>
            <option value="aprobada"  {{ filtroEstado == 'aprobada'  ? 'selected' : '' }}>Aprobada</option>
            <option value="rechazada" {{ filtroEstado == 'rechazada' ? 'selected' : '' }}>Rechazada</option>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </div>
</form>

{% if solicitudes|length == 0 %}
<div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>No hay solicitudes.</div>
{% else %}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-striped align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Empleado</th><th>Tipo</th><th>Inicio</th><th>Fin</th>
                    <th class="text-end">Horas</th><th>Estado</th><th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                {% for s in solicitudes %}
                <tr>
                    <td class="fw-semibold">{{ s.apellidos }}, {{ s.nombre }}</td>
                    <td>
                        {% if s.tipo == 'horas_extra' %}Horas extra
                        {% elseif s.tipo == 'vacaciones' %}Vacaciones
                        {% else %}Permiso{% endif %}
                    </td>
                    <td>{{ s.fecha_inicio|date('d/m/Y') }}</td>
                    <td>{{ s.fecha_fin ? s.fecha_fin|date('d/m/Y') : '—' }}</td>
                    <td class="text-end">{{ s.horas ?? '—' }}</td>
                    <td>
                        {% if s.estado == 'pendiente' %}<span class="badge bg-warning text-dark">Pendiente</span>
                        {% elseif s.estado == 'aprobada' %}<span class="badge bg-success">Aprobada</span>
                        {% else %}<span class="badge bg-danger">Rechazada</span>{% endif %}
                    </td>
                    <td class="text-center text-nowrap">
                        {% if s.estado == 'pendiente' %}
                        <a href="{{ url_for('solicitudes.edit', {'id': s.id_solicitud}) }}" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil-square"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-success" title="Aprobar"
                                data-bs-toggle="modal" data-bs-target="#modalResolver"
                                data-url="{{ url_for('solicitudes.aprobar', {'id': s.id_solicitud}) }}"
                                data-accion="aprobar" data-info="{{ s.apellidos }}, {{ s.nombre }}"><i class="bi bi-check-lg"></i></button>
                        <button type="button" class="btn btn-sm btn-outline-warning" title="Rechazar"
                                data-bs-toggle="modal" data-bs-target="#modalResolver"
                                data-url="{{ url_for('solicitudes.rechazar', {'id': s.id_solicitud}) }}"
                                data-accion="rechazar" data-info="{{ s.apellidos }}, {{ s.nombre }}"><i class="bi bi-x-lg"></i></button>
                        {% endif %}
                        {% if s.estado != 'aprobada' %}
                        <button type="button" class="btn btn-sm btn-outline-danger" title="Eliminar"
                                data-bs-toggle="modal" data-bs-target="#modalEliminar"
                                data-url="{{ url_for('solicitudes.destroy', {'id': s.id_solicitud}) }}"
                                data-info="{{ s.apellidos }}, {{ s.nombre }}"><i class="bi bi-trash3"></i></button>
                        {% endif %}
                    </td>
                </tr>
                {% endfor %}
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">Total: {{ solicitudes|length }} solicitud(es).</div>
</div>
{% endif %}

<div class="modal fade" id="modalResolver" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formResolver" method="POST" action="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalResolverTitulo">Resolver solicitud</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Solicitud de <strong id="modalResolverInfo"></strong>.</p>
                    <label for="observacion" class="form-label">Observación (opcional)</label>
                    <textarea class="form-control" id="observacion" name="observacion" maxlength="255" rows="2"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="modalResolverBtn">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEliminar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Confirmar eliminación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">¿Eliminar la solicitud de <strong id="modalEliminarInfo"></strong>?</div>
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
document.getElementById('modalResolver').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    const accion = btn.getAttribute('data-accion');
    document.getElementById('formResolver').action = btn.getAttribute('data-url');
    document.getElementById('modalResolverInfo').textContent = btn.getAttribute('data-info');
    document.getElementById('modalResolverTitulo').textContent = (accion === 'aprobar' ? 'Aprobar' : 'Rechazar') + ' solicitud';
    const confirmBtn = document.getElementById('modalResolverBtn');
    confirmBtn.textContent = (accion === 'aprobar' ? 'Aprobar' : 'Rechazar');
    confirmBtn.className = 'btn ' + (accion === 'aprobar' ? 'btn-success' : 'btn-warning');
});
document.getElementById('modalEliminar').addEventListener('show.bs.modal', function (event) {
    const btn = event.relatedTarget;
    document.getElementById('formEliminar').action = btn.getAttribute('data-url');
    document.getElementById('modalEliminarInfo').textContent = btn.getAttribute('data-info');
});
</script>
{% endblock %}
```

Commit `feat: add solicitudes index template with filters and resolve modal`.

---

## Task 5: form template

Create `templates/solicitudes/form.html.twig`:

```twig
{% extends 'layouts/base.html.twig' %}

{% block titulo %}{{ titulo }}{% endblock %}

{% block breadcrumb %}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ url_for('dashboard') }}">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="{{ url_for('solicitudes.index') }}">Solicitudes</a></li>
        <li class="breadcrumb-item active">{{ accion == 'crear' ? 'Nueva' : 'Editar' }}</li>
    </ol>
</nav>
{% endblock %}

{% block contenido %}
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-inbox me-2"></i>{{ titulo }}</h5>
            </div>
            <div class="card-body">

                {% if errores|length > 0 %}
                <div class="alert alert-danger"><ul class="mb-0">{% for e in errores %}<li>{{ e }}</li>{% endfor %}</ul></div>
                {% endif %}

                {% if accion == 'crear' %}
                    {% set form_action = url_for('solicitudes.store') %}
                {% else %}
                    {% set form_action = url_for('solicitudes.update', {'id': solicitud.id_solicitud}) %}
                {% endif %}

                <form method="POST" action="{{ form_action }}" novalidate>

                    <div class="mb-3">
                        <label for="id_empleado" class="form-label fw-semibold">Empleado <span class="text-danger">*</span></label>
                        <select class="form-select" id="id_empleado" name="id_empleado" required>
                            <option value="">— Seleccione —</option>
                            {% for e in empleados %}
                            <option value="{{ e.id_empleado }}" {{ (solicitud.id_empleado ?? '') == e.id_empleado ? 'selected' : '' }}>{{ e.apellidos }}, {{ e.nombre }}</option>
                            {% endfor %}
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="tipo" class="form-label fw-semibold">Tipo <span class="text-danger">*</span></label>
                        <select class="form-select" id="tipo" name="tipo" required>
                            <option value="">— Seleccione —</option>
                            <option value="horas_extra" {{ (solicitud.tipo ?? '') == 'horas_extra' ? 'selected' : '' }}>Horas extra</option>
                            <option value="vacaciones"  {{ (solicitud.tipo ?? '') == 'vacaciones'  ? 'selected' : '' }}>Vacaciones</option>
                            <option value="permiso"     {{ (solicitud.tipo ?? '') == 'permiso'     ? 'selected' : '' }}>Permiso</option>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="fecha_inicio" class="form-label fw-semibold">Fecha de inicio <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required value="{{ solicitud.fecha_inicio ?? '' }}">
                        </div>
                        <div class="col-md-6">
                            <label for="fecha_fin" class="form-label fw-semibold">Fecha de fin</label>
                            <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" value="{{ solicitud.fecha_fin ?? '' }}">
                            <div class="form-text">Requerida para vacaciones y permisos.</div>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label for="horas" class="form-label fw-semibold">Horas</label>
                        <input type="number" step="0.01" min="0" max="999.99" class="form-control" id="horas" name="horas" value="{{ solicitud.horas ?? '' }}">
                        <div class="form-text">Solo para solicitudes de horas extra.</div>
                    </div>

                    <div class="mb-3">
                        <label for="motivo" class="form-label fw-semibold">Motivo</label>
                        <textarea class="form-control" id="motivo" name="motivo" maxlength="255" rows="2">{{ solicitud.motivo ?? '' }}</textarea>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ url_for('solicitudes.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Cancelar</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>{{ accion == 'crear' ? 'Guardar' : 'Actualizar' }}</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

Commit `feat: add solicitudes form template`.

---

## Task 6: Wire DI + routes + navbar

**dependencies.php** — add after the Asistencia entries:
```php
    \App\Repositories\SolicitudesRepository::class => function (ContainerInterface $c) {
        return new \App\Repositories\SolicitudesRepository($c->get(PDO::class));
    },
```
```php
    \App\Services\SolicitudesService::class => function (ContainerInterface $c) {
        return new \App\Services\SolicitudesService(
            $c->get(\App\Repositories\SolicitudesRepository::class),
            $c->get(\App\Repositories\EmpleadosRepository::class),
            $c->get(\App\Repositories\AuditoriaRepository::class)
        );
    },
```
```php
    \App\Controllers\SolicitudesController::class => function (ContainerInterface $c) {
        return new \App\Controllers\SolicitudesController(
            $c->get(Twig::class),
            $c->get(\App\Services\SolicitudesService::class)
        );
    },
```

**routes.php** — add `use App\Controllers\SolicitudesController;` and, after the `/asistencia` group:
```php
    // ── Solicitudes (solo admin) ──────────────────────────
    $app->group('/solicitudes', function (RouteCollectorProxy $group) {
        $group->get('',                  [SolicitudesController::class, 'index'])->setName('solicitudes.index');
        $group->get('/crear',            [SolicitudesController::class, 'create'])->setName('solicitudes.create');
        $group->post('/crear',           [SolicitudesController::class, 'store'])->setName('solicitudes.store');
        $group->get('/{id}/editar',      [SolicitudesController::class, 'edit'])->setName('solicitudes.edit');
        $group->post('/{id}/editar',     [SolicitudesController::class, 'update'])->setName('solicitudes.update');
        $group->post('/{id}/aprobar',    [SolicitudesController::class, 'aprobar'])->setName('solicitudes.aprobar');
        $group->post('/{id}/rechazar',   [SolicitudesController::class, 'rechazar'])->setName('solicitudes.rechazar');
        $group->post('/{id}/eliminar',   [SolicitudesController::class, 'destroy'])->setName('solicitudes.destroy');
    })->add(new RoleMiddleware('admin'))->add(new AuthMiddleware());
```

**base.html.twig** — add a "Solicitudes" item as the FIRST item inside the Operaciones dropdown `<ul class="dropdown-menu">` (before Asistencia):
```twig
                        <li><a class="dropdown-item" href="{{ url_for('solicitudes.index') }}"><i class="bi bi-inbox me-1"></i>Solicitudes</a></li>
```

Lint both PHP config files. Commit `feat: wire Solicitudes (DI, routes, navbar)`.

---

## Task 7: Integration verification (controller-run)
Boot container + routes (no DB); confirm route parser resolves solicitudes.index/create/store/edit/update/aprobar/rechazar/destroy. Confirm no path_for/base_url.

## Task 8: Manual smoke test (user)
Create solicitudes of each tipo, validation per tipo, approve/reject with observación, edit-only-pending guard, delete guards (aprobada / con dependientes), filters, auditoría.
