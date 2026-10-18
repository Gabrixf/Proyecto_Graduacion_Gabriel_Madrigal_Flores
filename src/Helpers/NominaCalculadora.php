<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * NominaCalculadora — cálculo puro de la planilla (sin BD ni HTTP).
 * Tasas CCSS por defecto en el constructor o por fecha en deduccionesCCSS().
 */
final class NominaCalculadora
{
    public function __construct(
        // Las tasas reales llegan por fecha desde ParametrosLegalesService.
        private readonly float $ccssObrera = 0.0,
        private readonly float $ccssPatronal = 0.0
    ) {}

    /** Valor de la hora ordinaria: salario mensual / 240 (30 días × 8 h). */
    public function valorHora(float $salarioMensual): float
    {
        return $salarioMensual / 240.0;
    }

    /** Monto de horas extra = valor hora × cantidad × factor de recargo. */
    public function montoHorasExtra(float $salarioMensual, float $horas, float $factor): float
    {
        return round($this->valorHora($salarioMensual) * $horas * $factor, 2);
    }

    /**
     * Líneas de deducción CCSS. La patronal se marca informativa (no reduce el neto).
     *
     * @param array{ccss_obrera?: float, ccss_patronal?: float}|null $tasas tasas vigentes a la fecha; si faltan se usan las del constructor
     * @return array<int, array{tipo:string, descripcion:string, porcentaje:float, monto:float, informativa:bool}>
     */
    public function deduccionesCCSS(float $bruto, ?array $tasas = null): array
    {
        $obrera   = (float)($tasas['ccss_obrera'] ?? $this->ccssObrera);
        $patronal = (float)($tasas['ccss_patronal'] ?? $this->ccssPatronal);
        return [
            [
                'tipo'        => 'CCSS_empleado',
                'descripcion' => 'CCSS cuota obrera',
                'porcentaje'  => round($obrera * 100, 2),
                'monto'       => round($bruto * $obrera, 2),
                'informativa' => false,
            ],
            [
                'tipo'        => 'CCSS_patronal',
                'descripcion' => 'CCSS cuota patronal (informativa)',
                'porcentaje'  => round($patronal * 100, 2),
                'monto'       => round($bruto * $patronal, 2),
                'informativa' => true,
            ],
        ];
    }

    /**
     * Totales de la nómina. Las deducciones con informativa=true se excluyen del neto.
     *
     * @param array<int, array{monto: float|int|string}> $ingresos
     * @param array<int, array{monto: float|int|string, informativa?: bool}> $deducciones
     * @return array{total_ingresos: float, total_deducciones: float, salario_bruto: float, salario_neto: float}
     */
    public function calcularTotales(array $ingresos, array $deducciones): array
    {
        $bruto = 0.0;
        foreach ($ingresos as $i) {
            $bruto += (float) $i['monto'];
        }

        $totalDed = 0.0;
        foreach ($deducciones as $d) {
            if (!empty($d['informativa'])) {
                continue; // CCSS patronal u otra línea informativa
            }
            $totalDed += (float) $d['monto'];
        }

        $bruto    = round($bruto, 2);
        $totalDed = round($totalDed, 2);

        return [
            'total_ingresos'    => $bruto,
            'total_deducciones' => $totalDed,
            'salario_bruto'     => $bruto,
            'salario_neto'      => round($bruto - $totalDed, 2),
        ];
    }
}
