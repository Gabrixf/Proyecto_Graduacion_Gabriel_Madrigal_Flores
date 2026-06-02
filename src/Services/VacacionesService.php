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
