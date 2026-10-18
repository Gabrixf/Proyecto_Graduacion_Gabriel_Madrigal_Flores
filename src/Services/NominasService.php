<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\CalculadoraNominaPeriodo;
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
        private readonly ?DeteccionHorasExtraService $deteccion = null,
        private readonly ?ParametrosLegalesService $parametros = null,
        private readonly ?DiasHabilesService $diasHabiles = null,
        private readonly ?CalculadoraNominaPeriodo $calculadora = null
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
        $periodo = $this->repo->periodo($idPeriodo);
        if ($periodo === null) {
            throw new InvalidArgumentException('El periodo seleccionado no existe.');
        }
        // Parámetros antes de crear nada: si falta uno, no se genera ninguna nómina.
        $tasas      = $this->tasas((string)$periodo['fecha_inicio']);
        $tablaRenta = $this->esSegundaQuincena($periodo) && $this->parametros !== null
            ? $this->parametros->tablaRenta((string)$periodo['fecha_inicio'])
            : null;

        $generadas = 0;
        foreach ($this->repo->empleadosActivosSinNomina($idPeriodo) as $emp) {
            $idNomina = $this->generarUno($emp, $idPeriodo, $periodo, $tasas, $tablaRenta);
            $this->auditoriaRepo->insert('INSERT', $loggedInId, 'nominas', $idNomina, $ip);
            $generadas++;
        }
        return $generadas;
    }

    /**
     * Adicional sencillo por cada feriado o día de descanso trabajado (Arts. 149 y 152; con salario
     * mensual el pago sencillo ya está en el salario y se agrega el otro para completar el doble).
     * Si RRHH corrige la asistencia con una nómina en borrador ya generada, esta línea no se recalcula:
     * hay que regenerar el borrador (eliminarlo y generarlo de nuevo).
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

    /**
     * Construye y persiste una nómina para un empleado.
     * @param array<string, mixed> $emp     fila de empleadosActivosSinNomina
     * @param array<string, mixed> $periodo fecha_inicio, fecha_fin
     */
    private function generarUno(array $emp, int $idPeriodo, array $periodo, array $tasas, ?array $tablaRenta): int
    {
        $idEmpleado = (int)$emp['id_empleado'];
        $salario    = (float)$emp['salario_mensual'];

        $adicionales = [];
        foreach ($this->repo->horasExtraDelPeriodo($idEmpleado, $idPeriodo) as $he) {
            $fecha     = (string)$he['fecha'];
            $posterior = $fecha < (string)$periodo['fecha_inicio'] || $fecha > (string)$periodo['fecha_fin'];
            $adicionales[] = $this->lineaHorasExtra($fecha, (float)$he['cantidad_horas'], (float)$he['factor_recargo'], $salario, $posterior);
        }
        foreach ($this->adicionalesDiaEspecial($idEmpleado, $idPeriodo, $salario) as $linea) {
            $adicionales[] = $linea;
        }

        $r = $this->calculadora()->calcular(
            $this->datosCalculo($idEmpleado, $idPeriodo, $salario, $periodo, $tasas, $tablaRenta, $emp) + ['lineas_adicionales' => $adicionales]
        );

        return $this->repo->insertNominaCompleta(
            [
                'id_empleado'       => $idEmpleado,
                'id_periodo'        => $idPeriodo,
                'salario_base'      => round($salario / 2.0, 2),
                'total_ingresos'    => $r['totales']['total_ingresos'],
                'total_deducciones' => $r['totales']['total_deducciones'],
                'salario_bruto'     => $r['totales']['salario_bruto'],
                'salario_neto'      => $r['totales']['salario_neto'],
                'estado'            => 'borrador',
            ],
            $r['ingresos'],
            $r['deducciones']
        );
    }

    /**
     * Entrada de CalculadoraNominaPeriodo sin las líneas adicionales.
     * @param array<string, mixed> $periodo
     * @param array<string, mixed> $emp
     * @return array<string, mixed>
     */
    private function datosCalculo(int $idEmpleado, int $idPeriodo, float $salario, array $periodo, array $tasas, ?array $tablaRenta, array $emp): array
    {
        $ini = (string)$periodo['fecha_inicio'];
        $fin = (string)$periodo['fecha_fin'];

        $incapacidades = array_map(static function (array $i): array {
            $anterior = (string)$i['fin_ccss_anterior'];
            $reinc = $i['tipo'] === 'CCSS' && $anterior !== '0000-00-00'
                && (new DateTimeImmutable((string)$i['fecha_inicio']))->diff(new DateTimeImmutable($anterior))->days <= 30;
            return ['tipo' => (string)$i['tipo'], 'fecha_inicio' => (string)$i['fecha_inicio'],
                    'fecha_fin' => (string)$i['fecha_fin'], 'reincapacidad' => $reinc];
        }, $this->repo->incapacidadesDelPeriodo($idEmpleado, $ini, $fin));

        $diasPermiso = 0.0;
        foreach ($this->repo->permisosSinGoceDelPeriodo($idEmpleado, $ini, $fin) as $p) {
            $desde = max((string)$p['fecha_inicio'], $ini);
            $hasta = min((string)$p['fecha_fin'], $fin);
            $diasPermiso += $this->diasHabiles !== null
                ? $this->diasHabiles->contar($desde, $hasta, $idEmpleado)
                : (float)((new DateTimeImmutable($hasta))->diff(new DateTimeImmutable($desde))->days + 1);
        }

        $inicio  = new DateTimeImmutable($ini);
        $bruto50 = $this->repo->brutoEntre($idEmpleado, $inicio->modify('-350 days')->format('Y-m-d'), $inicio->modify('-1 day')->format('Y-m-d'));
        $promedio = $bruto50 > 0 ? $bruto50 / 350 : $salario / 30;

        $renta = null;
        if ($tablaRenta !== null) {
            $gravable1 = 0.0;
            foreach ($this->repo->lineasPrimeraQuincena($idEmpleado, $inicio->format('Y-m-01')) as $l) {
                if (CalculadoraNominaPeriodo::esGravable((string)$l['tipo'])) {
                    $gravable1 += (float)$l['monto'];
                }
            }
            $renta = [
                'tabla'                     => $tablaRenta,
                'hijos'                     => (int)($emp['renta_hijos'] ?? 0),
                'conyuge'                   => (int)($emp['renta_conyuge'] ?? 0) === 1,
                'gravable_primera_quincena' => round($gravable1, 2),
            ];
        }

        return [
            'salario_mensual'            => $salario,
            'fecha_inicio'               => $ini,
            'fecha_fin'                  => $fin,
            'incapacidades'              => $incapacidades,
            'dias_permiso_sin_goce'      => $diasPermiso,
            'dias_vacaciones'            => $this->repo->diasVacacionesDelPeriodo($idEmpleado, $idPeriodo),
            'promedio_diario_50_semanas' => $promedio,
            'tasas'                      => $tasas,
            'renta'                      => $renta,
        ];
    }

    /** 2.ª quincena = período que empieza el día 16. @param array<string, mixed> $periodo */
    private function esSegundaQuincena(array $periodo): bool
    {
        return (int)substr((string)$periodo['fecha_inicio'], 8, 2) === 16;
    }

    /** @return array{ccss_obrera: float|null, ccss_patronal: float|null} */
    private function tasas(string $fecha): array
    {
        return $this->parametros !== null
            ? $this->parametros->tasasCcss($fecha)
            : ['ccss_obrera' => null, 'ccss_patronal' => null];   // sin servicio: la calculadora usa sus tasas por defecto
    }

    private function calculadora(): CalculadoraNominaPeriodo
    {
        return $this->calculadora ?? new CalculadoraNominaPeriodo($this->calc);
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
