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

    public function listar(?int $idPeriodo = null, ?int $idEmpleado = null, ?string $tipo = null, ?string $q = null): array
    {
        return $this->repo->findAll($idPeriodo, $idEmpleado, $tipo, $q);
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
