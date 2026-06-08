<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * LiquidacionCalculadora — cálculo puro de la liquidación laboral
 * (Código de Trabajo CR, Arts. 28-29). Sin BD ni HTTP.
 */
final class LiquidacionCalculadora
{
    /** Tabla de cesantía Art. 29: días por año (1..8). Tope: 8 años. */
    private const CESANTIA = [
        1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0,
        5 => 21.24, 6 => 21.5, 7 => 22.0, 8 => 22.0,
    ];

    /** Valor de un día: salario mensual / 30. */
    public function valorDia(float $salarioMensual): float
    {
        return $salarioMensual / 30.0;
    }

    /**
     * Antigüedad entre dos fechas (YYYY-MM-DD).
     * @return array{anios:int, meses:int, mesesTotales:int, fraccion:float}
     */
    public function antiguedad(string $fechaIngreso, string $fechaSalida): array
    {
        $ini  = new DateTimeImmutable($fechaIngreso);
        $fin  = new DateTimeImmutable($fechaSalida);
        $diff = $ini->diff($fin);
        $anios = $diff->y;
        $meses = $diff->m;
        return [
            'anios'        => $anios,
            'meses'        => $meses,
            'mesesTotales' => $anios * 12 + $meses,
            'fraccion'     => $meses / 12.0,
        ];
    }

    /** Días de preaviso (Art. 28) según meses totales de antigüedad. */
    public function diasPreaviso(int $mesesTotales): int
    {
        if ($mesesTotales < 3) {
            return 0;
        }
        if ($mesesTotales < 6) {
            return 7;
        }
        if ($mesesTotales < 12) {
            return 15;
        }
        return 30;
    }

    public function montoPreaviso(float $salarioMensual, int $mesesTotales): float
    {
        return round($this->diasPreaviso($mesesTotales) * $this->valorDia($salarioMensual), 2);
    }

    /** Días de cesantía (Art. 29) con tope de 8 años. Aplica solo con >= 3 meses. */
    public function diasCesantia(int $anios, float $fraccion, int $mesesTotales): float
    {
        if ($mesesTotales < 3) {
            return 0.0;
        }
        $n = min($anios, 8);
        $dias = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $dias += self::CESANTIA[$i];
        }
        if ($anios < 8) {
            $dias += self::CESANTIA[$n + 1] * $fraccion;
        }
        return round($dias, 2);
    }

    public function montoCesantia(float $salarioMensual, int $anios, float $fraccion, int $mesesTotales): float
    {
        return round($this->diasCesantia($anios, $fraccion, $mesesTotales) * $this->valorDia($salarioMensual), 2);
    }

    public function montoVacaciones(float $salarioMensual, float $diasPendientes): float
    {
        return round($diasPendientes * $this->valorDia($salarioMensual), 2);
    }

    /** Aguinaldo proporcional: salario × meses del 1-dic (o ingreso) a la salida ÷ 12. */
    public function aguinaldoProporcional(float $salarioMensual, string $fechaIngreso, string $fechaSalida): float
    {
        $fin  = new DateTimeImmutable($fechaSalida);
        $anio = (int) $fin->format('Y');
        $dicEsteAnio = new DateTimeImmutable($anio . '-12-01');
        $inicio = ($fin >= $dicEsteAnio)
            ? $dicEsteAnio
            : new DateTimeImmutable(($anio - 1) . '-12-01');

        $ingreso = new DateTimeImmutable($fechaIngreso);
        if ($ingreso > $inicio) {
            $inicio = $ingreso;
        }
        if ($inicio >= $fin) {
            return 0.0;
        }
        $d = $inicio->diff($fin);
        $meses = $d->y * 12 + $d->m + ($d->d / 30.0);
        return round($salarioMensual * $meses / 12.0, 2);
    }

    /**
     * Calcula todos los rubros aplicando el mapeo por motivo.
     *
     * @param array{salarioMensual:float, fechaIngreso:string, fechaSalida:string, motivo:string, diasVacaciones:float} $d
     * @return array{preaviso:float, cesantia:float, vacaciones_pendientes:float, aguinaldo_proporcional:float, total_liquidacion:float}
     */
    public function calcular(array $d): array
    {
        $ant    = $this->antiguedad($d['fechaIngreso'], $d['fechaSalida']);
        $motivo = $d['motivo'];

        $aplicaPreaviso = $motivo === 'despido_sin_causa';
        $aplicaCesantia = in_array($motivo, ['despido_sin_causa', 'jubilacion'], true);

        $preaviso = $aplicaPreaviso
            ? $this->montoPreaviso($d['salarioMensual'], $ant['mesesTotales'])
            : 0.0;
        $cesantia = $aplicaCesantia
            ? $this->montoCesantia($d['salarioMensual'], $ant['anios'], $ant['fraccion'], $ant['mesesTotales'])
            : 0.0;
        $vacaciones = $this->montoVacaciones($d['salarioMensual'], $d['diasVacaciones']);
        $aguinaldo  = $this->aguinaldoProporcional($d['salarioMensual'], $d['fechaIngreso'], $d['fechaSalida']);

        $total = round($preaviso + $cesantia + $vacaciones + $aguinaldo, 2);

        return [
            'preaviso'               => $preaviso,
            'cesantia'               => $cesantia,
            'vacaciones_pendientes'  => $vacaciones,
            'aguinaldo_proporcional' => $aguinaldo,
            'total_liquidacion'      => $total,
        ];
    }
}
