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
            return 0.0;
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
