<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/**
 * EvaluacionCalculadora — cálculo puro del puntaje ponderado de una
 * evaluación de rendimiento (escala 1–100, pesos porcentuales). Sin BD ni HTTP.
 */
final class EvaluacionCalculadora
{
    private const TOLERANCIA_PESOS = 0.01;

    /** Los pesos porcentuales deben sumar 100 (tolerancia 0.01). */
    public function validarPesos(array $pesos): bool
    {
        return abs(array_sum($pesos) - 100.0) <= self::TOLERANCIA_PESOS;
    }

    /**
     * Promedio ponderado de los criterios, redondeado a 2 decimales solo al final.
     *
     * @param array<int, array{puntaje: float, peso: float}> $detalles
     */
    public function puntajeTotal(array $detalles): float
    {
        if ($detalles === []) {
            throw new InvalidArgumentException('La evaluación debe tener al menos un criterio.');
        }
        $pesos = array_map(static fn(array $d): float => (float) $d['peso'], $detalles);
        if (!$this->validarPesos($pesos)) {
            throw new InvalidArgumentException('Los pesos de los criterios deben sumar 100.');
        }
        $total = 0.0;
        foreach ($detalles as $d) {
            $puntaje = (float) $d['puntaje'];
            if ($puntaje < 1.0 || $puntaje > 100.0) {
                throw new InvalidArgumentException('Cada puntaje debe estar entre 1 y 100.');
            }
            $total += $puntaje * (float) $d['peso'];
        }
        return round($total / 100.0, 2);
    }
}
