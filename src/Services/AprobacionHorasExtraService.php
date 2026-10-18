<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\HorasExtraRepository;
use App\Repositories\NominasRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * AprobacionHorasExtraService — el admin aprueba las horas extra detectadas por la asistencia.
 * Recalcula con la asistencia actual, crea el registro en `horas_extra` y lo lleva a la nómina
 * del periodo de la fecha (o al siguiente abierto si esa nómina ya se aprobó o pagó).
 */
class AprobacionHorasExtraService
{
    public function __construct(
        private readonly SolicitudesRepository      $solicitudesRepo,
        private readonly AsistenciaRepository       $asistenciaRepo,
        private readonly HorasExtraRepository       $horasExtraRepo,
        private readonly PeriodosRepository         $periodosRepo,
        private readonly NominasRepository          $nominasRepo,
        private readonly NominasService             $nominas,
        private readonly DeteccionHorasExtraService $deteccion,
        private readonly AuditoriaRepository        $auditoriaRepo
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?string $estado): array
    {
        $estado = in_array($estado, ['pendiente', 'aprobada', 'rechazada'], true) ? $estado : 'pendiente';
        $filas  = $this->solicitudesRepo->findAll('horas_extra', $estado, null, null, 'asistencia');
        foreach ($filas as &$f) {
            $asis = $this->asistenciaRepo->findByEmpleadoFecha((int)$f['id_empleado'], (string)$f['fecha_inicio']);
            $c    = $asis !== null
                ? $this->deteccion->clasificar((int)$f['id_empleado'], (string)$f['fecha_inicio'], (string)$asis['hora_entrada'], (string)$asis['hora_salida'])
                : null;
            $f['hora_entrada']      = $asis !== null ? substr((string)$asis['hora_entrada'], 0, 5) : '—';
            $f['hora_salida']       = $asis !== null ? substr((string)$asis['hora_salida'], 0, 5) : '—';
            $f['salida_horario']    = $this->deteccion->salidaProgramada((int)$f['id_empleado'], (string)$f['fecha_inicio']) ?? '—';
            $f['clasificacion']     = $c;
            $f['aviso_12h']         = $c !== null && $c['excede_12h'];
            $f['asistencia_cambio'] = $c !== null && abs($c['horas_extra'] - (float)$f['horas']) > 0.009;
            $salario                = $this->nominasRepo->salarioMensualEmpleado((int)$f['id_empleado']) ?? 0.0;
            $f['monto_estimado']    = $c !== null
                ? $this->nominas->lineaHorasExtra((string)$f['fecha_inicio'], (float)$f['horas'], $c['factor_extra'], $salario, false)['monto']
                : 0.0;
        }
        unset($f);
        return $filas;
    }

    public function aprobar(int $idSolicitud, float $horas, int $idUsuario, string $ip): void
    {
        $s = $this->solicitudesRepo->findById($idSolicitud);
        if ($s === null || ($s['origen'] ?? '') !== 'asistencia' || $s['tipo'] !== 'horas_extra') {
            throw new RuntimeException('Solicitud de horas extra detectadas no encontrada.');
        }
        if ($s['estado'] !== 'pendiente') {
            throw new RuntimeException('Solo se aprueban solicitudes pendientes.');
        }
        $idEmpleado = (int)$s['id_empleado'];
        $fecha      = (string)$s['fecha_inicio'];
        $asis = $this->asistenciaRepo->findByEmpleadoFecha($idEmpleado, $fecha);
        $c    = $asis !== null
            ? $this->deteccion->clasificar($idEmpleado, $fecha, (string)$asis['hora_entrada'], (string)$asis['hora_salida'])
            : null;
        if ($c === null || $c['horas_extra'] <= 0) {
            throw new RuntimeException('La asistencia de ese día ya no tiene horas extra; revísala.');
        }
        $horas = round($horas, 2);
        if ($horas <= 0 || $horas > $c['horas_extra']) {
            throw new InvalidArgumentException(sprintf('Puedes aprobar entre 0.01 y %s h (lo marcado).', number_format($c['horas_extra'], 2)));
        }

        [$idPeriodo, $nomina, $posterior] = $this->periodoDePago($idEmpleado, $fecha);

        $clave = $c['factor_extra'] >= 2.0 ? 'factor_he_especial' : 'factor_he_ord';
        $idHe  = $this->horasExtraRepo->insert([
            'id_solicitud'   => $idSolicitud,
            'id_empleado'    => $idEmpleado,
            'id_periodo'     => $idPeriodo,
            'fecha'          => $fecha,
            'cantidad_horas' => $horas,
            'factor_recargo' => $c['factor_extra'],
            'id_parametro'   => $this->horasExtraRepo->findParametroId($clave, $fecha) ?? 1,
        ]);
        $this->solicitudesRepo->resolver($idSolicitud, 'aprobada', $idUsuario, null);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $idSolicitud, $ip);
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'horas_extra', $idHe, $ip);

        if ($nomina !== null && $nomina['estado'] === 'borrador') {
            $this->nominas->agregarHorasExtra((int)$nomina['id_nomina'], $fecha, $horas, $c['factor_extra'], $posterior, $idUsuario, $ip);
        }
    }

    /**
     * Periodo donde se pagan: el de la fecha si su nómina no existe o está en borrador;
     * si ya se aprobó o pagó, el siguiente periodo abierto.
     * @return array{0: int, 1: array<string, mixed>|null, 2: bool}
     */
    private function periodoDePago(int $idEmpleado, string $fecha): array
    {
        $periodo = $this->periodosRepo->findPorFecha($fecha);
        if ($periodo === null) {
            throw new RuntimeException('No hay periodo de pago para el ' . $fecha . '.');
        }
        $nomina = $this->nominasRepo->findCabeceraEmpleadoPeriodo($idEmpleado, (int)$periodo['id_periodo']);
        if ($nomina === null || $nomina['estado'] === 'borrador') {
            return [(int)$periodo['id_periodo'], $nomina, false];
        }
        $desde = (string)$periodo['fecha_fin'];
        while (($siguiente = $this->periodosRepo->findSiguienteAbierto($desde)) !== null) {
            $idSig  = (int)$siguiente['id_periodo'];
            $nomSig = $this->nominasRepo->findCabeceraEmpleadoPeriodo($idEmpleado, $idSig);
            if ($nomSig === null || $nomSig['estado'] === 'borrador') {
                return [$idSig, $nomSig, true];
            }
            $desde = (string)$siguiente['fecha_fin'];
        }
        throw new RuntimeException('La nómina de ese periodo ya se cerró y no hay un periodo abierto siguiente para pagarlas.');
    }
}
