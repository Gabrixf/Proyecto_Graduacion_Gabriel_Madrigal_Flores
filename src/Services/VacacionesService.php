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
        private readonly AuditoriaRepository   $auditoriaRepo,
        private readonly SaldoVacacionesService $saldoVacaciones,
        private readonly DiasHabilesService    $diasHabiles
    ) {}

    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $q = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado, $q);
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
        $dias    = $this->resolverDias($datos['dias_tomados'] ?? '', $fechaInicio, $fechaFin, $idEmpleado, $errores);
        $periodo = $this->repo->findPeriodoAbiertoPorFecha($fechaInicio);
        if ($periodo === null) {
            $errores[] = 'No hay un periodo de pago abierto que contenga la fecha de inicio.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $anio = (int)substr($fechaInicio, 0, 4);
        $this->verificarSaldoSuficiente($idEmpleado, $fechaInicio, $dias, $idSolicitud);

        $id = $this->repo->insert([
            'id_solicitud' => $idSolicitud,
            'id_empleado'  => $idEmpleado,
            'id_periodo'   => (int)$periodo['id_periodo'],
            'fecha_inicio' => $fechaInicio,
            'fecha_fin'    => $fechaFin,
            'dias_tomados' => $dias,
            'anio'         => $anio,
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
            (int)$vac['id_empleado'],
            $errores
        );
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }
        $this->verificarSaldoSuficiente(
            (int)$vac['id_empleado'],
            (string)$vac['fecha_inicio'],
            $dias,
            (int)$vac['id_solicitud']
        );

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
     * Es el saldo por antigüedad a hoy (ganados menos todo lo ya solicitado).
     *
     * @return array<string, mixed>|null
     */
    public function saldoDeVacacion(int $idVacacion): ?array
    {
        $vac = $this->repo->findById($idVacacion);
        if ($vac === null) {
            return null;
        }
        $empleado = $this->empleadosRepo->findById((int)$vac['id_empleado']);
        if ($empleado === null) {
            return null;
        }
        $hoy   = new DateTimeImmutable('today');
        $saldo = $this->saldoVacaciones->saldo((int)$vac['id_empleado'], (string)$empleado['fecha_ingreso'], $hoy);
        return ['anio' => (int)$hoy->format('Y'), 'dias_disponibles' => $saldo['disponibles']];
    }

    /**
     * El derecho se acumula por antigüedad (1 día por mes completo) y no se puede tomar
     * durante el período de prueba; ver SaldoVacacionesService. `$excluirSolicitud` es la
     * solicitud que origina este registro, para no contar sus días dos veces.
     */
    private function verificarSaldoSuficiente(
        int $idEmpleado,
        string $fechaInicio,
        float $diasSolicitados,
        int $excluirSolicitud
    ): void {
        $empleado = $this->empleadosRepo->findById($idEmpleado);
        if ($empleado === null) {
            throw new RuntimeException('Empleado no encontrado.');
        }
        $this->saldoVacaciones->validarDisponibilidad(
            $idEmpleado,
            (string)$empleado['fecha_ingreso'],
            $fechaInicio,
            $diasSolicitados,
            $excluirSolicitud
        );
    }

    /**
     * @param string[] $errores
     */
    private function resolverDias(mixed $raw, string $ini, string $fin, int $idEmpleado, array &$errores): float
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
        return $this->diasHabiles->contar($ini, $fin, $idEmpleado);
    }
}
