<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NominaCalculadora;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NominasRepository;
use App\Services\DeteccionHorasExtraService;
use DateTimeImmutable;
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
        private readonly NominaCalculadora   $calc,
        private readonly ?DeteccionHorasExtraService $deteccion = null
    ) {}

    private const ESTADOS = ['borrador', 'aprobado', 'pagado'];

    /** @return array<int, array<string, mixed>> */
    public function listar(?int $idPeriodo = null, ?string $estado = null): array
    {
        return $this->repo->findAll($idPeriodo, $estado);
    }

    /**
     * Datos de la pantalla de nóminas. Sin periodo en la URL se muestra el periodo por
     * defecto (ver periodoPorDefecto); 'todos' muestra el histórico completo.
     *
     * @return array{nominas: array<int, array<string, mixed>>, periodos: array<int, array<string, mixed>>,
     *               filtroPeriodo: int|string, periodo: array<string, mixed>|null,
     *               filtroEstado: string|null, resumen: array{cantidad: int, bruto: float, deducciones: float, neto: float}}
     */
    public function vistaListado(?string $periodoParam, ?string $estadoParam, DateTimeImmutable $hoy): array
    {
        $periodos = $this->repo->periodos();
        $estado   = in_array($estadoParam, self::ESTADOS, true) ? $estadoParam : null;

        if ($periodoParam === 'todos') {
            $idPeriodo = null;
        } elseif ($periodoParam !== null && ctype_digit($periodoParam)) {
            $idPeriodo = (int)$periodoParam;
        } else {
            $idPeriodo = $this->periodoPorDefecto($periodos, $hoy);
        }

        $periodo = null;
        foreach ($periodos as $p) {
            if ((int)$p['id_periodo'] === $idPeriodo) {
                $periodo = $p;
                break;
            }
        }
        if ($periodo === null) {
            $idPeriodo = null;   // periodo inexistente en la URL: se muestra el histórico
        }

        $nominas = $this->repo->findAll($idPeriodo, $estado);
        return [
            'nominas'       => $nominas,
            'periodos'      => $periodos,
            'filtroPeriodo' => $idPeriodo ?? 'todos',
            'periodo'       => $periodo,
            'filtroEstado'  => $estado,
            'resumen'       => $this->resumen($nominas),
        ];
    }

    /**
     * Periodo que se muestra al entrar: el abierto que contiene hoy; si no hay, el abierto
     * más reciente que ya empezó; si tampoco, el más reciente que ya empezó.
     *
     * @param array<int, array<string, mixed>> $periodos ordenados por fecha_inicio DESC
     */
    public function periodoPorDefecto(array $periodos, DateTimeImmutable $hoy): ?int
    {
        $fecha     = $hoy->format('Y-m-d');
        $iniciados = array_values(array_filter($periodos, static fn ($p) => (string)$p['fecha_inicio'] <= $fecha));
        $abiertos  = array_values(array_filter($iniciados, static fn ($p) => $p['estado'] === 'abierto'));

        foreach ($abiertos as $p) {
            if ((string)$p['fecha_fin'] >= $fecha) {
                return (int)$p['id_periodo'];
            }
        }
        $elegido = $abiertos[0] ?? $iniciados[0] ?? null;
        return $elegido !== null ? (int)$elegido['id_periodo'] : null;
    }

    /**
     * Totales de las nóminas listadas (lo que se paga en el periodo).
     * @param array<int, array<string, mixed>> $nominas
     * @return array{cantidad: int, bruto: float, deducciones: float, neto: float}
     */
    public function resumen(array $nominas): array
    {
        $suma = static fn (string $campo): float => round(array_sum(array_map(
            static fn ($n) => (float)$n[$campo],
            $nominas
        )), 2);
        return [
            'cantidad'    => count($nominas),
            'bruto'       => $suma('salario_bruto'),
            'deducciones' => $suma('total_deducciones'),
            'neto'        => $suma('salario_neto'),
        ];
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
        $periodo   = $this->repo->periodo($idPeriodo) ?? ['fecha_inicio' => '0000-00-00', 'fecha_fin' => '9999-12-31'];
        $empleados = $this->repo->empleadosActivosSinNomina($idPeriodo);
        $generadas = 0;
        foreach ($empleados as $emp) {
            $idNomina = $this->generarUno((int) $emp['id_empleado'], $idPeriodo, (float) $emp['salario_mensual'], $periodo);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'nominas', $idNomina, $ip);
            $generadas++;
        }
        return $generadas;
    }

    /**
     * Adicional sencillo por cada feriado o día de descanso trabajado (Arts. 149 y 152; con salario
     * mensual el pago sencillo ya está en el salario y se agrega el otro para completar el doble).
     * @return array<int, array{tipo: string, descripcion: string, monto: float}>
     */
    private function adicionalesDiaEspecial(int $idEmpleado, int $idPeriodo, float $salarioMensual): array
    {
        if ($this->deteccion === null) {
            return [];
        }
        $lineas = [];
        foreach ($this->repo->asistenciasDelPeriodo($idEmpleado, $idPeriodo) as $a) {
            $c = $this->deteccion->clasificar($idEmpleado, (string)$a['fecha'], (string)$a['hora_entrada'], (string)$a['hora_salida']);
            if ($c === null || $c['horas_dia_especial'] <= 0) {
                continue;
            }
            $feriado  = $c['tipo_dia'] === 'feriado';
            $fecha    = (new \DateTimeImmutable((string)$a['fecha']))->format('d/m/Y');
            $lineas[] = [
                'tipo'        => $feriado ? 'feriado' : 'descanso_trabajado',
                'descripcion' => sprintf('%s %s: %s h', $feriado ? 'Feriado trabajado' : 'Día de descanso trabajado', $fecha, number_format($c['horas_dia_especial'], 2)),
                'monto'       => round($c['horas_dia_especial'] * $this->calc->valorHora($salarioMensual), 2),
            ];
        }
        return $lineas;
    }

    /** Construye y persiste una nómina para un empleado. */
    private function generarUno(int $idEmpleado, int $idPeriodo, float $salarioMensual, array $periodo): int
    {
        $salarioQuincena = round($salarioMensual / 2.0, 2);

        $ingresos = [[
            'tipo'        => 'salario_base',
            'descripcion' => 'Salario base quincenal',
            'monto'       => $salarioQuincena,
        ]];

        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $fecha     = (string)$he['fecha'];
            $posterior = $fecha < (string)$periodo['fecha_inicio'] || $fecha > (string)$periodo['fecha_fin'];
            $ingresos[] = $this->lineaHorasExtra($fecha, (float)$he['cantidad_horas'], (float)$he['factor_recargo'], $salarioMensual, $posterior);
        }
        foreach ($this->adicionalesDiaEspecial($idEmpleado, $idPeriodo, $salarioMensual) as $linea) {
            $ingresos[] = $linea;
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

        $tipo  = in_array($datos['tipo'] ?? '', ['bonificacion', 'feriado', 'otro'], true)
            ? $datos['tipo'] : 'otro';
        $monto = $this->montoValido($datos['monto'] ?? '');

        $this->repo->insertIngreso($idNomina, [
            'tipo'        => $tipo,
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')),
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
            'descripcion' => trim((string) ($datos['descripcion'] ?? '')),
            'porcentaje'  => 0.0,
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

    /** Línea de ingreso de horas extra. @return array{tipo: string, descripcion: string, monto: float} */
    public function lineaHorasExtra(string $fecha, float $horas, float $factor, float $salarioMensual, bool $posterior): array
    {
        $f = (new DateTimeImmutable($fecha))->format('d/m/Y');
        return [
            'tipo'        => 'horas_extra',
            'descripcion' => sprintf('Horas extra %s: %s h × %s%s', $f, number_format($horas, 2), number_format($factor, 2), $posterior ? ' (pago posterior)' : ''),
            'monto'       => $this->calc->montoHorasExtra($salarioMensual, $horas, $factor),
        ];
    }

    public function agregarHorasExtra(int $idNomina, string $fecha, float $horas, float $factor, bool $posterior, int $idUsuario, string $ip): void
    {
        $nomina = $this->obtener($idNomina);
        $this->assertBorrador($nomina);
        $salario = $this->repo->salarioMensualEmpleado((int)$nomina['id_empleado']) ?? 0.0;
        $this->repo->insertIngreso($idNomina, $this->lineaHorasExtra($fecha, $horas, $factor, $salario, $posterior));
        $this->recalcular($idNomina);
        $this->auditoriaRepo->insert('UPDATE', $idUsuario, 'nominas', $idNomina, $ip);
    }

    /** Recalcula la CCSS sobre el bruto actual y luego los totales. */
    private function recalcular(int $idNomina): void
    {
        $n = $this->repo->findByIdConLineas($idNomina);
        if ($n === null) {
            return;
        }
        $bruto = round(array_sum(array_map(static fn ($i) => (float)$i['monto'], $n['ingresos'])), 2);
        $this->repo->deleteDeduccionesCCSS($idNomina);
        $ccss = $this->calc->deduccionesCCSS($bruto);
        foreach ($ccss as $d) {
            $this->repo->insertDeduccion($idNomina, $d);
        }
        $otras = array_filter($n['deducciones'], static fn ($d) => !in_array($d['tipo'], ['CCSS_empleado', 'CCSS_patronal'], true));
        $deducciones = array_merge(
            array_map(static fn ($d) => ['monto' => (float)$d['monto'], 'informativa' => false], array_values($otras)),
            $ccss
        );
        $this->repo->updateTotales($idNomina, $this->calc->calcularTotales($n['ingresos'], $deducciones));
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
