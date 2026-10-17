<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * Cuenta los días hábiles de un rango de fechas. Función pura: no toca la base de datos.
 * Días hábiles: lunes a sábado (igual que CalendarioAsistencia; el taller no tiene
 * horarios todavía). El domingo es el día de descanso semanal (Art. 152 del Código de
 * Trabajo) y los feriados no se rebajan de las vacaciones.
 */
final class DiasHabiles
{
    /**
     * @param string[] $feriados fechas (Y-m-d) feriadas dentro del rango
     * @return int días hábiles entre $inicio y $fin, inclusive; 0 si el rango es inválido
     */
    public function contar(string $inicio, string $fin, array $feriados): int
    {
        $dia   = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $hasta = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($dia === false || $hasta === false) {
            return 0;
        }

        $esFeriado = array_flip($feriados);
        $total     = 0;
        for (; $dia <= $hasta; $dia = $dia->modify('+1 day')) {
            if ((int)$dia->format('N') !== 7 && !isset($esFeriado[$dia->format('Y-m-d')])) {
                $total++;
            }
        }
        return $total;
    }
}
