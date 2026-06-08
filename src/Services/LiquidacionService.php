<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\LiquidacionCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\LiquidacionRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * LiquidacionService — cálculo y gestión de liquidaciones laborales.
 */
class LiquidacionService
{
    private const MOTIVOS = ['renuncia', 'despido_con_causa', 'despido_sin_causa', 'mutuo_acuerdo', 'jubilacion'];

    public function __construct(
        private readonly LiquidacionRepository  $repo,
        private readonly AuditoriaRepository    $auditoriaRepo,
        private readonly LiquidacionCalculadora $calc
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(): array
    {
        return $this->repo->findAll();
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new RuntimeException('Liquidación no encontrada.');
        }
        return $row;
    }

    /** @return array{empleados: array<int, array<string, mixed>>, motivos: string[]} */
    public function datosFormulario(): array
    {
        return ['empleados' => $this->repo->empleadosActivos(), 'motivos' => self::MOTIVOS];
    }

    /** Calcula y persiste la liquidación de un empleado. @return int id_liquidacion */
    public function calcular(array $datos, int $loggedInId, string $ip): int
    {
        $idEmpleado  = isset($datos['id_empleado']) && is_numeric($datos['id_empleado']) ? (int) $datos['id_empleado'] : 0;
        $motivo      = (string) ($datos['motivo'] ?? '');
        $fechaSalida = trim((string) ($datos['fecha_salida'] ?? ''));
        $diasVac     = $datos['dias_vacaciones'] ?? '0';

        $errores = [];
        $emp = $idEmpleado > 0 ? $this->repo->datosEmpleado($idEmpleado) : null;
        if ($emp === null) {
            $errores[] = 'Debe seleccionar un empleado válido.';
        }
        if (!in_array($motivo, self::MOTIVOS, true)) {
            $errores[] = 'El motivo seleccionado no es válido.';
        }
        if ($fechaSalida === '' || strtotime($fechaSalida) === false) {
            $errores[] = 'La fecha de salida no es válida.';
        } elseif ($emp !== null && $fechaSalida < (string) $emp['fecha_ingreso']) {
            $errores[] = 'La fecha de salida no puede ser anterior a la fecha de ingreso.';
        }
        if (!is_numeric($diasVac) || (float) $diasVac < 0) {
            $errores[] = 'Los días de vacaciones pendientes deben ser un número mayor o igual a 0.';
        }
        if (!empty($errores)) {
            throw new InvalidArgumentException(implode(' ', $errores));
        }

        $montos = $this->calc->calcular([
            'salarioMensual' => (float) $emp['salario_base'],
            'fechaIngreso'   => (string) $emp['fecha_ingreso'],
            'fechaSalida'    => $fechaSalida,
            'motivo'         => $motivo,
            'diasVacaciones' => round((float) $diasVac, 2),
        ]);

        $id = $this->repo->upsert(array_merge(
            ['id_empleado' => $idEmpleado, 'fecha_salida' => $fechaSalida, 'motivo' => $motivo],
            $montos
        ));
        $this->auditoriaRepo->insert('INSERT', $loggedInId, 'liquidacion', $id, $ip);
        return $id;
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $this->obtener($id);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'liquidacion', $id, $ip);
    }
}
