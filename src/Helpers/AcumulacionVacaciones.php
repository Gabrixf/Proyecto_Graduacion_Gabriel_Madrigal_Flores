<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * AcumulacionVacaciones — regla pura de antigüedad para vacaciones (sin BD ni HTTP).
 * Se gana `diasPorMes` por cada mes completo trabajado desde el ingreso; los primeros
 * `mesesPrueba` meses son período de prueba, en el que no se pueden solicitar.
 * Los parámetros se inyectan desde config/settings.php para no hardcodear.
 */
final class AcumulacionVacaciones
{
    public function __construct(
        private readonly int   $mesesPrueba = 3,
        private readonly float $diasPorMes  = 1.0
    ) {}

    /** Días acumulados desde el ingreso hasta `$referencia` (0 si es anterior al ingreso). */
    public function diasGanados(DateTimeImmutable $ingreso, DateTimeImmutable $referencia): float
    {
        return $this->mesesCompletos($ingreso, $referencia) * $this->diasPorMes;
    }

    /** Primer día en que el empleado deja de estar en período de prueba. */
    public function finPrueba(DateTimeImmutable $ingreso): DateTimeImmutable
    {
        return $this->sumarMeses($ingreso, $this->mesesPrueba);
    }

    public function enPrueba(DateTimeImmutable $ingreso, DateTimeImmutable $fecha): bool
    {
        return $fecha < $this->finPrueba($ingreso);
    }

    private function mesesCompletos(DateTimeImmutable $ingreso, DateTimeImmutable $referencia): int
    {
        if ($referencia < $ingreso) {
            return 0;
        }
        $meses = ((int)$referencia->format('Y') - (int)$ingreso->format('Y')) * 12
               + ((int)$referencia->format('n') - (int)$ingreso->format('n'));
        if ($this->sumarMeses($ingreso, $meses) > $referencia) {
            $meses--;
        }
        return max($meses, 0);
    }

    /** Suma meses; si el día no existe en el mes destino, usa el último día de ese mes. */
    private function sumarMeses(DateTimeImmutable $fecha, int $meses): DateTimeImmutable
    {
        $primero = $fecha->modify('first day of this month')->modify("+{$meses} months");
        $dia     = min((int)$fecha->format('j'), (int)$primero->format('t'));
        return $primero->setDate((int)$primero->format('Y'), (int)$primero->format('n'), $dia);
    }
}
