<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * LiquidacionCalculadora — cálculo puro de la liquidación laboral (Código de Trabajo CR, Arts. 28, 29,
 * 30, 83-85 y 157; Ley 2412). Recibe ya calculadas la base del Art. 30, el valor diario de las
 * vacaciones y los brutos del aguinaldo; aquí solo se aplican las reglas. Sin BD ni HTTP.
 */
final class LiquidacionCalculadora
{
    /** Art. 29 inciso 3: días por año según la antigüedad total (13 = "13 y siguientes"). */
    private const TASA_CESANTIA = [
        1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0, 5 => 21.24, 6 => 21.5, 7 => 22.0,
        8 => 22.0, 9 => 22.0, 10 => 21.5, 11 => 21.0, 12 => 20.5, 13 => 20.0,
    ];

    /** Art. 29 inciso 4: nunca más que los últimos ocho años. */
    private const TOPE_ANIOS = 8;

    /** Motivos que pagan preaviso (Arts. 28, 83-84). */
    public const CON_PREAVISO = ['despido_sin_causa', 'renuncia_justificada'];

    /** Motivos que pagan cesantía (Arts. 29, 83-85). */
    public const CON_CESANTIA = ['despido_sin_causa', 'renuncia_justificada', 'muerte', 'jubilacion'];

    /** @return array{anios:int, meses:int, dias:int, mesesTotales:int, masDeSeisMeses:bool} */
    public function antiguedad(string $fechaIngreso, string $fechaSalida): array
    {
        $d = (new DateTimeImmutable($fechaIngreso))->diff(new DateTimeImmutable($fechaSalida));
        return [
            'anios'          => $d->y,
            'meses'          => $d->m,
            'dias'           => $d->d,
            'mesesTotales'   => $d->y * 12 + $d->m,
            // fracción del último año "superior a seis meses" (6 meses y al menos un día)
            'masDeSeisMeses' => $d->m > 6 || ($d->m === 6 && $d->d > 0),
        ];
    }

    /** Art. 28: 3 a 6 meses → 7; más de 6 meses y menos de 1 año → 15; 1 año o más → 30. */
    public function diasPreaviso(array $a): float
    {
        if ($a['anios'] >= 1) {
            return 30.0;
        }
        if ($a['mesesTotales'] < 3) {
            return 0.0;
        }
        return $a['masDeSeisMeses'] ? 15.0 : 7.0;
    }

    /**
     * Art. 29: antes del año, 7 o 14 días; desde el año, una sola tasa (la de los años completos)
     * × años contados (una fracción mayor de seis meses suma un año), con tope de 8 años.
     * @return array{dias:float, anios_contados:int, tasa:float}
     */
    public function diasCesantia(array $a): array
    {
        if ($a['anios'] >= 1) {
            $tasa    = $this->tasaCesantia($a['anios']);
            $contados = min($a['anios'] + ($a['masDeSeisMeses'] ? 1 : 0), self::TOPE_ANIOS);
            return ['dias' => round($tasa * $contados, 2), 'anios_contados' => $contados, 'tasa' => $tasa];
        }
        if ($a['mesesTotales'] < 3) {
            return ['dias' => 0.0, 'anios_contados' => 0, 'tasa' => 0.0];
        }
        return ['dias' => $a['masDeSeisMeses'] ? 14.0 : 7.0, 'anios_contados' => 0, 'tasa' => 0.0];
    }

    public function tasaCesantia(int $aniosCompletos): float
    {
        return self::TASA_CESANTIA[min(max($aniosCompletos, 1), 13)];
    }

    /**
     * Días entre dos fechas (inclusive) en el mes comercial de 30 días: el día 31 no cuenta y el último
     * día de febrero completa el mes hasta 30.
     */
    public static function diasComerciales(string $desde, string $hasta): int
    {
        $ini = new DateTimeImmutable($desde);
        if ((int) $ini->format('d') === 31) {
            $ini = $ini->modify('+1 day');
        }
        $fin = new DateTimeImmutable($hasta);
        if ($ini > $fin) {
            return 0;
        }
        $d1 = min((int) $ini->format('d'), 30);
        $d2 = $fin->format('d') === $fin->format('t') ? 30 : min((int) $fin->format('d'), 30);
        $dias = ((int) $fin->format('Y') - (int) $ini->format('Y')) * 360
              + ((int) $fin->format('n') - (int) $ini->format('n')) * 30
              + ($d2 - $d1) + 1;
        return max(0, $dias);
    }

    /**
     * @param array{fechaIngreso:string, fechaSalida:string, motivo:string, salarioPromedio:float,
     *              salarioDiarioVacaciones:float, diasVacaciones:float, brutosAguinaldo:float} $d
     * @return array<string, float|int>
     */
    public function calcular(array $d): array
    {
        $ant     = $this->antiguedad($d['fechaIngreso'], $d['fechaSalida']);
        $valorDia = $d['salarioPromedio'] / 30.0;   // Art. 30

        $diasPreaviso = in_array($d['motivo'], self::CON_PREAVISO, true) ? $this->diasPreaviso($ant) : 0.0;
        $ces = in_array($d['motivo'], self::CON_CESANTIA, true)
            ? $this->diasCesantia($ant)
            : ['dias' => 0.0, 'anios_contados' => 0, 'tasa' => 0.0];
        $diasVac = max(0.0, $d['diasVacaciones']);

        $preaviso   = round($diasPreaviso * $valorDia, 2);
        $cesantia   = round($ces['dias'] * $valorDia, 2);
        $vacaciones = round($diasVac * $d['salarioDiarioVacaciones'], 2);   // Art. 157
        $aguinaldo  = round($d['brutosAguinaldo'] / 12.0, 2);              // Ley 2412

        return [
            'preaviso'               => $preaviso,
            'cesantia'               => $cesantia,
            'vacaciones_pendientes'  => $vacaciones,
            'aguinaldo_proporcional' => $aguinaldo,
            'total_liquidacion'      => round($preaviso + $cesantia + $vacaciones + $aguinaldo, 2),
            'dias_preaviso'          => $diasPreaviso,
            'dias_cesantia'          => $ces['dias'],
            'dias_vacaciones'        => $diasVac,
            'salario_promedio'       => round($d['salarioPromedio'], 2),
            'anios_contados'         => $ces['anios_contados'],
            'tasa_cesantia'          => $ces['tasa'],
        ];
    }
}
