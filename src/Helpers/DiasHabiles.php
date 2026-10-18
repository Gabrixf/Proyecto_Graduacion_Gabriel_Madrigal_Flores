<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * Cuenta los días hábiles de un rango de fechas. Función pura: no toca la base de datos.
 * Días hábiles: los que el horario del empleado trabaja (por defecto lunes a sábado;
 * el domingo es el descanso semanal, Art. 152 del Código de Trabajo). Los feriados no
 * se rebajan de las vacaciones.
 */
final class DiasHabiles
{
    /**
     * @param string[] $feriados     fechas (Y-m-d) feriadas dentro del rango
     * @param int[]    $diasDescanso días ISO (1 = lunes ... 7 = domingo) de descanso del empleado
     * @return int días hábiles entre $inicio y $fin, inclusive; 0 si el rango es inválido
     */
    public function contar(string $inicio, string $fin, array $feriados, array $diasDescanso = [7]): int
    {
        $dia   = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        $hasta = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
        if ($dia === false || $hasta === false) {
            return 0;
        }

        $esFeriado = array_flip($feriados);
        $descanso  = array_flip($diasDescanso);
        $total     = 0;
        for (; $dia <= $hasta; $dia = $dia->modify('+1 day')) {
            if (!isset($descanso[(int)$dia->format('N')]) && !isset($esFeriado[$dia->format('Y-m-d')])) {
                $total++;
            }
        }
        return $total;
    }
}
