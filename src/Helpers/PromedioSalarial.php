<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * PromedioSalarial — promedio diario de lo devengado en una ventana, dividido entre los días que de
 * verdad cubren las nóminas (Código de Trabajo, Arts. 30 y 157). Un empleado reciente no se promedia
 * contra días en los que no trabajaba. Sin BD ni reloj.
 */
final class PromedioSalarial
{
    /**
     * $bruto ÷ días entre el mayor de ($desde, $ingreso, $primerInicioNomina) y el menor de
     * ($hasta, $ultimoFinNomina), con tope $maxDias. Null si el bruto es 0 o no hay días.
     */
    public static function diario(
        float $bruto,
        string $desde,
        string $hasta,
        ?string $ingreso,
        ?string $primerInicioNomina,
        ?string $ultimoFinNomina = null,
        int $maxDias = 350
    ): ?float {
        if ($bruto <= 0) {
            return null;
        }
        $inicio = $desde;
        foreach ([$ingreso, $primerInicioNomina] as $limite) {
            if ($limite !== null && $limite > $inicio) {
                $inicio = $limite;
            }
        }
        $fin = $hasta;
        if ($ultimoFinNomina !== null && $ultimoFinNomina < $fin) {
            $fin = $ultimoFinNomina;
        }
        if ($inicio > $fin) {
            return null;
        }
        $dias = min($maxDias, (new DateTimeImmutable($inicio))->diff(new DateTimeImmutable($fin))->days + 1);
        return $bruto / $dias;
    }
}
