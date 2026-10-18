<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * CalculadoraNominaPeriodo — reglas legales de la nómina quincenal. Función pura: sin BD ni reloj.
 *
 * - Salario diario = mensual ÷ 30; la quincena se paga como 15 días.
 * - Incapacidad CCSS: el patrono paga el 50 % de los días 1–3 (Código de Trabajo Art. 79 según
 *   MTSS DAJ-AE-221-04); desde el día 4 paga la CCSS (Reglamento del Seguro de Salud Art. 35);
 *   reincapacidad dentro de 30 días: la CCSS paga desde el día 1. INS y particular: el patrono no paga.
 * - El subsidio no es salario: no cotiza CCSS ni tributa renta (Sala II Voto 477-2012; Ley 7092 Art. 35).
 * - Vacaciones al promedio de las últimas 50 semanas si es mayor (Código de Trabajo Art. 157).
 * - Renta mensual progresiva (Ley 7092 Arts. 32–34; Decreto 45333-H), retenida en la 2.ª quincena.
 */
final class CalculadoraNominaPeriodo
{
    public const DIAS_QUINCENA = 15.0;
    private const DIAS_SUBSIDIO_PATRONAL = 3;

    public function __construct(private readonly NominaCalculadora $base) {}

    public static function esCotizable(string $tipo): bool
    {
        return $tipo !== 'subsidio_incapacidad';
    }

    public static function esGravable(string $tipo): bool
    {
        return $tipo !== 'subsidio_incapacidad';
    }

    /**
     * @param array<string, mixed> $e ver interfaz en el plan
     * @return array{ingresos: list<array<string, mixed>>, deducciones: list<array<string, mixed>>,
     *               totales: array<string, float>, dias_laborados: float, gravable: float}
     */
    public function calcular(array $e): array
    {
        $diario = (float)$e['salario_mensual'] / 30;
        $ini    = (string)$e['fecha_inicio'];
        $fin    = (string)$e['fecha_fin'];

        $diasIncapacidad = 0;
        $subsidios       = [];
        foreach ($e['incapacidades'] as $inc) {
            $diasIncapacidad += $this->diasIncapacidadEnPeriodo($inc, $ini, $fin);
            $dias = $this->diasSubsidioEnPeriodo($inc, $ini, $fin);
            if ($dias > 0) {
                $subsidios[] = [
                    'tipo'        => 'subsidio_incapacidad',
                    'descripcion' => sprintf('Subsidio patronal incapacidad %s–%s (50 %%)',
                        (new DateTimeImmutable((string)$inc['fecha_inicio']))->format('d/m'),
                        (new DateTimeImmutable((string)$inc['fecha_fin']))->format('d/m')),
                    'monto'       => round($dias * $diario * 0.5, 2),
                ];
            }
        }

        $diasVacaciones = (float)$e['dias_vacaciones'];
        $laborados = max(0.0, self::DIAS_QUINCENA - $diasIncapacidad - (float)$e['dias_permiso_sin_goce'] - $diasVacaciones);

        $ingresos = [[
            'tipo'        => 'salario_base',
            'descripcion' => sprintf('Salario quincenal: %s de 15 días', $this->numero($laborados)),
            'monto'       => round($laborados * $diario, 2),
        ]];
        if ($diasVacaciones > 0) {
            $ingresos[] = [
                'tipo'        => 'vacaciones',
                'descripcion' => sprintf('Vacaciones: %s días', $this->numero($diasVacaciones)),
                'monto'       => round($diasVacaciones * $diario, 2),
            ];
            $diferencia = (float)$e['promedio_diario_50_semanas'] - $diario;
            if ($diferencia > 0.004) {
                $ingresos[] = [
                    'tipo'        => 'vacaciones',
                    'descripcion' => 'Diferencia de vacaciones (Art. 157)',
                    'monto'       => round($diasVacaciones * $diferencia, 2),
                ];
            }
        }
        foreach ($subsidios as $s) {
            $ingresos[] = $s;
        }
        foreach ($e['lineas_adicionales'] as $l) {
            $ingresos[] = ['tipo' => (string)$l['tipo'], 'descripcion' => (string)$l['descripcion'], 'monto' => round((float)$l['monto'], 2)];
        }

        $deducciones = $this->deduccionesLegales($ingresos, (array)$e['tasas'], $e['renta']);

        return [
            'ingresos'       => $ingresos,
            'deducciones'    => $deducciones,
            'totales'        => $this->base->calcularTotales($ingresos, $deducciones),
            'dias_laborados' => $laborados,
            'gravable'       => $this->suma($ingresos, [self::class, 'esGravable']),
        ];
    }

    /**
     * CCSS sobre los ingresos cotizables y, si `$renta` no es null, la renta del mes.
     * @param list<array<string, mixed>> $ingresos
     * @param array{ccss_obrera: float, ccss_patronal: float} $tasas
     * @param array{tabla: array, hijos: int, conyuge: bool, gravable_primera_quincena: float}|null $renta
     * @return list<array<string, mixed>>
     */
    public function deduccionesLegales(array $ingresos, array $tasas, ?array $renta): array
    {
        $deducciones = $this->base->deduccionesCCSS($this->suma($ingresos, [self::class, 'esCotizable']), $tasas);
        if ($renta !== null) {
            $mes      = round((float)$renta['gravable_primera_quincena'] + $this->suma($ingresos, [self::class, 'esGravable']), 2);
            $impuesto = $this->impuestoRenta($mes, $renta['tabla'], (int)$renta['hijos'], (bool)$renta['conyuge']);
            if ($impuesto > 0) {
                $deducciones[] = [
                    'tipo'        => 'impuesto_renta',
                    'descripcion' => sprintf('Impuesto sobre la renta (gravable del mes ₡%s)', number_format($mes, 2, '.', ',')),
                    'porcentaje'  => 0.0,
                    'monto'       => $impuesto,
                    'informativa' => false,
                ];
            }
        }
        return $deducciones;
    }

    /** @param array{tramos: list<array{desde: float, tasa: float}>, credito_hijo: float, credito_conyuge: float} $tabla */
    public function impuestoRenta(float $baseMensual, array $tabla, int $hijos, bool $conyuge): float
    {
        $impuesto = 0.0;
        $tramos   = $tabla['tramos'];
        foreach ($tramos as $i => $t) {
            $hasta = $tramos[$i + 1]['desde'] ?? INF;
            $impuesto += max(0.0, min($baseMensual, $hasta) - $t['desde']) * $t['tasa'];
        }
        $creditos = $hijos * $tabla['credito_hijo'] + ($conyuge ? $tabla['credito_conyuge'] : 0.0);
        return round(max(0.0, $impuesto - $creditos), 2);
    }

    /**
     * Días de incapacidad a rebajar de la quincena, en el mes comercial de 30 días: el día 31 no cuenta y, si la
     * incapacidad cubre el último día de la 2.ª quincena de febrero, se suman los días virtuales hasta 30.
     * @param array<string, mixed> $inc
     */
    public function diasIncapacidadEnPeriodo(array $inc, string $ini, string $fin): int
    {
        $desde = max((string)$inc['fecha_inicio'], $ini);
        $hasta = min((string)$inc['fecha_fin'], $fin);
        if ($desde > $hasta) {
            return 0;
        }
        $dias = (new DateTimeImmutable($hasta))->diff(new DateTimeImmutable($desde))->days + 1;
        if (substr($hasta, 8, 2) === '31') {
            $dias--;   // el 31 no es día comercial (el período no cruza de mes, así que el 31 cae en el rango)
        }
        $finDePeriodo = new DateTimeImmutable($fin);
        if ($hasta === $fin && $finDePeriodo->format('m') === '02' && $fin === $finDePeriodo->format('Y-m-t')) {
            $dias += 30 - (int)$finDePeriodo->format('t');
        }
        return $dias;
    }

    /** Días 1–3 de una incapacidad CCSS (sin reincapacidad) que caen dentro del período. @param array<string, mixed> $inc */
    public function diasSubsidioEnPeriodo(array $inc, string $ini, string $fin): int
    {
        if ($inc['tipo'] !== 'CCSS' || !empty($inc['reincapacidad'])) {
            return 0;
        }
        $inicioInc = new DateTimeImmutable((string)$inc['fecha_inicio']);
        $dias = 0;
        for ($n = 0; $n < self::DIAS_SUBSIDIO_PATRONAL; $n++) {
            $f = $inicioInc->modify("+{$n} day")->format('Y-m-d');
            if ($f > (string)$inc['fecha_fin']) {
                break;
            }
            if ($f >= $ini && $f <= $fin) {
                $dias++;
            }
        }
        return $dias;
    }

    /** @param list<array<string, mixed>> $ingresos */
    private function suma(array $ingresos, callable $filtro): float
    {
        $s = 0.0;
        foreach ($ingresos as $i) {
            if ($filtro((string)$i['tipo'])) {
                $s += (float)$i['monto'];
            }
        }
        return round($s, 2);
    }

    private function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
