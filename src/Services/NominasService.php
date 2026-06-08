<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * NominasService — generación y gestión de nóminas quincenales.
 */
class NominasService
{
    public function __construct(
        private readonly NominasRepository   $repo,
        private readonly AuditoriaRepository $auditoriaRepo,
        private readonly NominaCalculadora   $calc
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idPeriodo = null): array
    {
        return $this->repo->findAll($idPeriodo);
    }

    /** @return array<int, array<string, mixed>> */
    public function periodos(): array
    {
        return $this->repo->periodos();
    }

    /** @return array<string, mixed> */
    public function obtener(int $id): array
    {
        $n = $this->repo->findByIdConLineas($id);
        if ($n === null) {
            throw new RuntimeException('Nómina no encontrada.');
        }
        return $n;
    }

    /**
     * Genera borradores de nómina para todos los empleados activos sin nómina en el periodo.
     * @return int cantidad generada
     */
    public function generarPeriodo(int $idPeriodo, int $loggedInId, string $ip): int
    {
        if (!$this->repo->existePeriodo($idPeriodo)) {
            throw new InvalidArgumentException('El periodo seleccionado no existe.');
        }
        $empleados = $this->repo->empleadosActivosSinNomina($idPeriodo);
        $generadas = 0;
        foreach ($empleados as $emp) {
            $idNomina = $this->generarUno((int) $emp['id_empleado'], $idPeriodo, (float) $emp['salario_mensual']);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'nominas', $idNomina, $ip);
            $generadas++;
        }
        return $generadas;
    }

    /** Construye y persiste una nómina para un empleado. */
    private function generarUno(int $idEmpleado, int $idPeriodo, float $salarioMensual): int
    {
        $salarioQuincena = round($salarioMensual / 2.0, 2);

        $ingresos = [[
            'tipo'        => 'salario_base',
            'descripcion' => 'Salario base quincenal',
            'monto'       => $salarioQuincena,
        ]];

        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $monto = $this->calc->montoHorasExtra(
                $salarioMensual,
                (float) $he['cantidad_horas'],
                (float) $he['factor_recargo']
            );
            $ingresos[] = [
                'tipo'        => 'horas_extra',
                'descripcion' => 'Horas extra ' . $he['fecha'] . ' (x' . $he['factor_recargo'] . ')',
                'monto'       => $monto,
            ];
        }

        $bruto       = array_sum(array_map(static fn ($i) => (float) $i['monto'], $ingresos));
        $deducciones = $this->calc->deduccionesCCSS(round($bruto, 2));
        $totales     = $this->calc->calcularTotales($ingresos, $deducciones);

        return $this->repo->insertNominaCompleta(
            [
                'id_empleado'       => $idEmpleado,
                'id_periodo'        => $idPeriodo,
                'salario_base'      => $salarioQuincena,
                'total_ingresos'    => $totales['total_ingresos'],
                'total_deducciones' => $totales['total_deducciones'],
                'salario_bruto'     => $totales['salario_bruto'],
                'salario_neto'      => $totales['salario_neto'],
                'estado'            => 'borrador',
            ],
            $ingresos,
            $deducciones
        );
    }

    /** Agrega una línea de ingreso manual y recalcula. */
    public function agregarIngreso(int $idNomina, array $datos, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);

        $tipo  = in_array($datos['tipo'] ?? '', ['bonificacion', 'comision', 'feriado', 'otro'], true)
            ? $datos['tipo'] : 'otro';
        $monto = $this->montoValido($datos['monto'] ?? '');

        $this->repo->insertIngreso($idNomina, [
            'tipo'        => $tipo,
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            'monto'       => $monto,
        ]);
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    /** Agrega una línea de deducción manual y recalcula. */
    public function agregarDeduccion(int $idNomina, array $datos, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);

        $tipo  = in_array($datos['tipo'] ?? '', ['embargo', 'otro'], true) ? $datos['tipo'] : 'otro';
        $monto = $this->montoValido($datos['monto'] ?? '');

        $this->repo->insertDeduccion($idNomina, [
            'tipo'        => $tipo,
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            'porcentaje'  => null,
            'monto'       => $monto,
        ]);
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    public function quitarLinea(int $idNomina, string $tipoLinea, int $idLinea, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);
        if ($tipoLinea === 'ingreso') {
            $this->repo->deleteIngreso($idNomina, $idLinea);
        } elseif ($tipoLinea === 'deduccion') {
            $this->repo->deleteDeduccion($idNomina, $idLinea);
        } else {
            throw new InvalidArgumentException('Tipo de línea inválido.');
        }
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $idNomina, $ip);
    }

    public function aprobar(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        if ($nomina['estado'] !== 'borrador') {
            throw new RuntimeException('Solo se puede aprobar una nómina en borrador.');
        }
        $this->repo->updateEstado($id, 'aprobado');
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $id, $ip);
    }

    public function marcarPagado(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        if ($nomina['estado'] !== 'aprobado') {
            throw new RuntimeException('Solo se puede pagar una nómina aprobada.');
        }
        $this->repo->updateEstado($id, 'pagado');
        $this->auditoriaRepo->insert('UPDATE', $loggedInId, 'nominas', $id, $ip);
    }

    public function eliminar(int $id, int $loggedInId, string $ip): void
    {
        $nomina = $this->obtener($id);
        $this->assertBorrador($nomina);
        $this->repo->delete($id);
        $this->auditoriaRepo->insert('DELETE', $loggedInId, 'nominas', $id, $ip);
    }

    /** Recalcula totales a partir de las líneas actuales. */
    private function recalcular(int $idNomina): void
    {
        $n = $this->repo->findByIdConLineas($idNomina);
        if ($n === null) {
            return;
        }
        $deducciones = array_map(
            static fn ($d) => [
                'monto'       => (float) $d['monto'],
                'informativa' => $d['tipo'] === 'CCSS_patronal',
            ],
            $n['deducciones']
        );
        $totales = $this->calc->calcularTotales($n['ingresos'], $deducciones);
        $this->repo->updateTotales($idNomina, $totales);
    }

    /** @param array<string, mixed> $nomina */
    private function assertBorrador(array $nomina): void
    {
        if (($nomina['estado'] ?? '') !== 'borrador') {
            throw new RuntimeException('La nómina no está en borrador; no se puede modificar.');
        }
    }

    private function montoValido(mixed $raw): float
    {
        if (!is_numeric($raw) || (float) $raw <= 0) {
            throw new InvalidArgumentException('El monto debe ser un número mayor a 0.');
        }
        return round((float) $raw, 2);
    }
}
