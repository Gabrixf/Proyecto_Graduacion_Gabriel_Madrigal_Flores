<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\PermisosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * PermisosService — registro de permisos derivados de solicitudes aprobadas.
 */
class PermisosService
{
    public function __construct(
        private readonly PermisosRepository    $repo,
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
            throw new RuntimeException('Permiso no encontrado.');
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
            if (($solicitud['tipo'] ?? '') !== 'permiso') {
                $errores[] = 'La solicitud seleccionada no es de tipo permiso.';
            }
            if (($solicitud['estado'] ?? '') !== 'aprobada') {
                $errores[] = 'La solicitud debe estar aprobada.';
            }
            if (empty($solicitud['fecha_fin'])) {
                $errores[] = 'La solicitud de permiso no tiene fecha de fin.';
            }
            if ($this->repo->existsBySolicitud($idSolicitud)) {
                $errores[] = 'Esta solicitud ya tiene un permiso registrado.';
            }
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $fechaInicio = (string)$solicitud['fecha_inicio'];
        $fechaFin    = (string)$solicitud['fecha_fin'];
        $idEmpleado  = (int)$solicitud['id_empleado'];

        $periodo = $this->repo->findPeriodoAbiertoPorFecha($fechaInicio);
        if ($periodo === null) {
            throw new InvalidArgumentException('No hay un periodo de pago abierto que contenga la fecha de inicio.');
        }

        $id = $this->repo->insert([
            'id_solicitud'      => $idSolicitud,
            'id_empleado'       => $idEmpleado,
            'id_periodo'        => (int)$periodo['id_periodo'],
            'fecha_inicio'      => $fechaInicio,
            'fecha_fin'         => $fechaFin,
            'con_goce_salarial' => $this->leerGoce($datos),
        ]);
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'permisos', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->update($id, $this->leerGoce($datos));
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'permisos', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'permisos', $id, $ip);
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function leerGoce(array $datos): int
    {
        return (isset($datos['con_goce_salarial']) && (string)$datos['con_goce_salarial'] === '1') ? 1 : 0;
    }
}
