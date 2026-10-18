<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * ClasificadorHoras — regla única para clasificar las horas de un día trabajado.
 * Función pura: no toca la base de datos ni el reloj.
 *
 * - Día normal: lo que pasa de la salida del horario es extra (×1.50, Art. 139).
 * - Feriado con horario: horas ordinarias = día especial (adicional sencillo, Art. 149), con tope en la
 *   jornada programada (salida − entrada − almuerzo del horario); lo que pasa de la salida es extra a pago
 *   triple (MTSS CP-005-2026). Un feriado parcial que abarque el almuerzo aún puede contarlo, porque no se
 *   guarda a qué hora del día es el almuerzo.
 * - Descanso o feriado sin horario: primeras 8 h = día especial (Art. 152); el resto extra ×3.00
 *   (en descanso, por analogía con el feriado).
 * - Menos de 15 minutos de extra no cuentan. Más de 12 h en el día se marca (Art. 140).
 */
final class ClasificadorHoras
{
    public const TOLERANCIA_HORAS = 0.25;
    public const MAX_DIA_ESPECIAL = 8.0;
    public const MAX_JORNADA      = 12.0;

    public function __construct(
        private readonly float $factorOrdinario,
        private readonly float $factorEspecial
    ) {}

    /**
     * @param array{entrada: string, salida: string, almuerzo?: int}|null $horarioDia null = ese día no tiene horario
     * @return array{tipo_dia: string, horas_trabajadas: float, horas_ordinarias: float,
     *               horas_dia_especial: float, horas_extra: float, factor_extra: float, excede_12h: bool}
     */
    public function clasificar(string $entrada, string $salida, ?array $horarioDia, bool $esFeriado): array
    {
        $trabajadas = max(0, $this->minutos($salida) - $this->minutos($entrada)) / 60;

        if ($horarioDia !== null) {
            $extra      = $this->tolerancia(max(0, $this->minutos($salida) - $this->minutos($horarioDia['salida'])) / 60);
            $extra      = min($extra, $trabajadas);
            $ordinarias = $trabajadas - $extra;
            $tipo       = $esFeriado ? 'feriado' : 'normal';
            $jornada    = max(0, $this->minutos($horarioDia['salida']) - $this->minutos($horarioDia['entrada']) - (int)($horarioDia['almuerzo'] ?? 0)) / 60;
            $especial   = $esFeriado ? min($ordinarias, $jornada) : 0.0;
            $factor     = $esFeriado ? $this->factorEspecial : $this->factorOrdinario;
        } else {
            $tipo       = $esFeriado ? 'feriado' : 'descanso';
            $especial   = min($trabajadas, self::MAX_DIA_ESPECIAL);
            $extra      = $this->tolerancia(max(0, $trabajadas - self::MAX_DIA_ESPECIAL));
            $ordinarias = 0.0;
            $factor     = $this->factorEspecial;
        }

        return [
            'tipo_dia'           => $tipo,
            'horas_trabajadas'   => round($trabajadas, 2),
            'horas_ordinarias'   => round($ordinarias, 2),
            'horas_dia_especial' => round($especial, 2),
            'horas_extra'        => round($extra, 2),
            'factor_extra'       => $factor,
            'excede_12h'         => $trabajadas > self::MAX_JORNADA,
        ];
    }

    private function tolerancia(float $horas): float
    {
        return $horas < self::TOLERANCIA_HORAS ? 0.0 : $horas;
    }

    /** Minutos desde medianoche de HH:MM o HH:MM:SS (los segundos se ignoran). */
    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', array_slice(explode(':', $hora), 0, 2)) + [0, 0];
        return $h * 60 + $m;
    }
}
