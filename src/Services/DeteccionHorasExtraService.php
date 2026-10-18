<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ClasificadorHoras;
use App\Repositories\AuditoriaRepository;
use App\Repositories\FeriadosRepository;
use App\Repositories\SolicitudesRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * DeteccionHorasExtraService — detecta horas extra comparando la salida marcada con el horario
 * y las deja como solicitud (origen = asistencia) para que el empleado justifique y el admin apruebe.
 * No accede a PDO ni a $_SESSION.
 */
class DeteccionHorasExtraService
{
    public const OPCIONES = ['horas_extra', 'no_trabajo', 'despues'];
    private const OBS_CIERRE = 'No se justificó antes del cierre del periodo';

    public function __construct(
        private readonly SolicitudesRepository $solicitudesRepo,
        private readonly HorariosService       $horarios,
        private readonly FeriadosRepository    $feriadosRepo,
        private readonly ClasificadorHoras     $clasificador,
        private readonly AuditoriaRepository   $auditoriaRepo
    ) {}

    public function validarJustificacion(string $opcion, string $motivo): void
    {
        if (!in_array($opcion, self::OPCIONES, true)) {
            throw new InvalidArgumentException('Elige por qué saliste tarde.');
        }
        if ($opcion === 'horas_extra' && trim($motivo) === '') {
            throw new InvalidArgumentException('Cuenta qué hiciste en las horas extra.');
        }
        if (mb_strlen(trim($motivo)) > 255) {
            throw new InvalidArgumentException('La justificación no puede pasar de 255 caracteres.');
        }
    }

    /** @return array<string, mixed>|null null si el empleado no tiene horario asignado */
    public function clasificar(int $idEmpleado, string $fecha, string $entrada, string $salida): ?array
    {
        if (!$this->horarios->tieneHorario($idEmpleado)) {
            return null;
        }
        return $this->clasificador->clasificar(
            $entrada,
            $salida,
            $this->horarios->horarioDelDia($idEmpleado, $fecha),
            $this->feriadosRepo->findEntre($fecha, $fecha) !== []
        );
    }

    public function salidaProgramada(int $idEmpleado, string $fecha): ?string
    {
        $h = $this->horarios->horarioDelDia($idEmpleado, $fecha);
        return $h !== null ? substr($h['salida'], 0, 5) : null;
    }

    public function registrar(
        int $idEmpleado, string $fecha, string $entrada, string $salida,
        string $opcion, string $motivo, int $idUsuario, string $ip
    ): void {
        $c = $this->clasificar($idEmpleado, $fecha, $entrada, $salida);
        if ($c === null || $c['horas_extra'] <= 0 || $opcion === 'no_trabajo') {
            return;
        }
        $existente = $this->solicitudesRepo->findDetectada($idEmpleado, $fecha);
        if ($existente !== null) {
            if ($existente['estado'] !== 'aprobada') {
                $this->solicitudesRepo->actualizarHoras((int)$existente['id_solicitud'], $c['horas_extra']);
            }
            return;
        }
        $pendiente = $opcion === 'horas_extra';
        $id = $this->solicitudesRepo->insertDetectada(
            $idEmpleado, $fecha, $c['horas_extra'],
            $pendiente ? trim($motivo) : '',
            $pendiente ? 'pendiente' : 'por_justificar'
        );
        $this->auditoriaRepo->insert('INSERT', $idUsuario, 'solicitudes', $id, $ip);
    }

    /** Corrección de una salida ya marcada (RRHH o el empleado). No pide justificación. */
    public function recalcular(int $idEmpleado, string $fecha, string $entrada, string $salida, int $idUsuario, string $ip): void
    {
        $c         = $this->clasificar($idEmpleado, $fecha, $entrada, $salida);
        $horas     = $c['horas_extra'] ?? 0.0;
        $existente = $this->solicitudesRepo->findDetectada($idEmpleado, $fecha);

        if ($existente === null) {
            if ($horas > 0) {
                $id = $this->solicitudesRepo->insertDetectada($idEmpleado, $fecha, $horas, '', 'por_justificar');
                $this->auditoriaRepo->insert('INSERT', $idUsuario, 'solicitudes', $id, $ip);
            }
            return;
        }
        if ($existente['estado'] === 'aprobada') {
            return;   // el aviso de "asistencia cambió" se calcula al listar (Task 10)
        }
        $id = (int)$existente['id_solicitud'];
        if ($horas <= 0) {
            $this->solicitudesRepo->delete($id);
            $this->auditoriaRepo->insert('DELETE', $idUsuario, 'solicitudes', $id, $ip);
            return;
        }
        $this->solicitudesRepo->actualizarHoras($id, $horas);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $id, $ip);
    }

    public function justificar(int $idSolicitud, int $idEmpleado, string $motivo, int $idUsuario, string $ip): void
    {
        $s = $this->solicitudesRepo->findById($idSolicitud, $idEmpleado);
        if ($s === null || ($s['origen'] ?? '') !== 'asistencia' || $s['estado'] !== 'por_justificar') {
            throw new RuntimeException('Esa solicitud no está esperando justificación.');
        }
        $this->validarJustificacion('horas_extra', $motivo);
        $this->solicitudesRepo->justificar($idSolicitud, trim($motivo));
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', $idSolicitud, $ip);
    }

    /** Al cerrar un periodo: lo que siga por justificar queda rechazado. @return int cantidad */
    public function rechazarSinJustificar(string $desde, string $hasta, int $idUsuario, string $ip): int
    {
        $filas = $this->solicitudesRepo->findPorJustificarEntre($desde, $hasta);
        foreach ($filas as $f) {
            $this->solicitudesRepo->resolver((int)$f['id_solicitud'], 'rechazada', $idUsuario, self::OBS_CIERRE);
            $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'solicitudes', (int)$f['id_solicitud'], $ip);
        }
        return count($filas);
    }
}
