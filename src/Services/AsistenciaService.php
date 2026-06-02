<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AsistenciaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * AsistenciaService — lógica de negocio del módulo de asistencia.
 * No accede a PDO ni a $_SESSION/$_SERVER (loggedInId/ip llegan como parámetros).
 */
class AsistenciaService
{
    public function __construct(
        private readonly AsistenciaRepository $repo,
        private readonly EmpleadosRepository  $empleadosRepo,
        private readonly PeriodosRepository   $periodosRepo,
        private readonly AuditoriaRepository  $auditoriaRepo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Registro de asistencia no encontrado.');
        }
        return $row;
    }

    /**
     * @return array{empleados: array<int, array<string, mixed>>, periodos: array<int, array<string, mixed>>}
     */
    public function datosFormulario(): array
    {
        return [
            'empleados' => $this->empleadosRepo->findAll('activo'),
            'periodos'  => $this->periodosRepo->findAll(),
        ];
    }

    public function crear(array $datos, int $loggedInId, string $ip): int
    {
        $fila = $this->validar($datos, null);
        try {
            $id = $this->repo->insert($fila);
        } catch (PDOException $e) {
            throw $this->traducir($e);
        }
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'asistencia', $id, $ip);
        return $id;
    }

    public function actualizar(int $id, array $datos, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $fila = $this->validar($datos, $id);
        try {
            $this->repo->update($id, $fila);
        } catch (PDOException $e) {
            throw $this->traducir($e);
        }
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'asistencia', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'asistencia', $id, $ip);
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function validar(array $d, ?int $excludeId): array
    {
        $errores = [];

        $idEmpleado = isset($d['id_empleado']) && is_numeric($d['id_empleado']) ? (int)$d['id_empleado'] : 0;
        $empleado   = $idEmpleado > 0 ? $this->empleadosRepo->findById($idEmpleado) : null;
        if ($empleado === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        } elseif (($empleado['estado'] ?? '') !== 'activo') {
            $errores[] = 'El empleado seleccionado no está activo.';
        }

        $fecha    = trim((string)($d['fecha'] ?? ''));
        $fechaObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($fechaObj === false) {
            $errores[] = 'La fecha es obligatoria y debe tener formato válido.';
        } elseif ($fechaObj > new DateTimeImmutable('today')) {
            $errores[] = 'La fecha no puede ser futura.';
        }

        $entrada    = trim((string)($d['hora_entrada'] ?? ''));
        $salida     = trim((string)($d['hora_salida'] ?? ''));
        $entradaObj = $entrada !== '' ? DateTimeImmutable::createFromFormat('!H:i', $entrada) : false;
        $salidaObj  = $salida !== ''  ? DateTimeImmutable::createFromFormat('!H:i', $salida)  : false;
        if ($entradaObj === false) {
            $errores[] = 'La hora de entrada es obligatoria (formato HH:MM).';
        }
        if ($salidaObj === false) {
            $errores[] = 'La hora de salida es obligatoria (formato HH:MM).';
        }
        if ($entradaObj !== false && $salidaObj !== false && $salidaObj <= $entradaObj) {
            $errores[] = 'La hora de salida debe ser posterior a la de entrada.';
        }

        // horas_trabajadas: override si el admin manda un valor > 0; si no, se calcula.
        $horas    = 0.0;
        $horasRaw = $d['horas_trabajadas'] ?? '';
        if (is_numeric($horasRaw) && (float)$horasRaw > 0) {
            $horas = round((float)$horasRaw, 2);
        } elseif ($entradaObj !== false && $salidaObj !== false && $salidaObj > $entradaObj) {
            $horas = round(($salidaObj->getTimestamp() - $entradaObj->getTimestamp()) / 3600, 2);
        }
        if ($horas <= 0 || $horas > 24) {
            $errores[] = 'Las horas trabajadas deben estar entre 0 y 24.';
        }

        // Periodo abierto que contiene la fecha.
        $idPeriodo = null;
        if ($fechaObj !== false) {
            $periodo = $this->repo->findPeriodoAbiertoPorFecha($fecha);
            if ($periodo === null) {
                $errores[] = 'No hay un periodo de pago abierto que contenga esa fecha.';
            } else {
                $idPeriodo = (int)$periodo['id_periodo'];
            }
        }

        // Duplicado (empleado, fecha).
        if ($idEmpleado > 0 && $fechaObj !== false
            && $this->repo->existsByEmpleadoFecha($idEmpleado, $fecha, $excludeId)) {
            $errores[] = 'Ya existe un registro de asistencia para ese empleado en esa fecha.';
        }

        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $idFeriado = $this->repo->findFeriadoIdPorFecha($fecha);

        return [
            'id_empleado'      => $idEmpleado,
            'id_periodo'       => $idPeriodo,
            'id_feriado'       => $idFeriado,
            'fecha'            => $fecha,
            'hora_entrada'     => $entrada,
            'hora_salida'      => $salida,
            'horas_trabajadas' => $horas,
        ];
    }

    private function traducir(PDOException $e): InvalidArgumentException
    {
        if (str_starts_with((string)$e->getCode(), '23')) {
            return new InvalidArgumentException('Ya existe un registro de asistencia para ese empleado en esa fecha.');
        }
        throw $e;
    }
}
