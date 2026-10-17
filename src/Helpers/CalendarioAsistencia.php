<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * Arma el calendario mensual de asistencia de un empleado (lunes a domingo)
 * y su resumen. Función pura: no toca la base de datos ni el reloj.
 * Días hábiles: lunes a sábado (el taller no tiene horarios todavía).
 */
final class CalendarioAsistencia
{
    /**
     * @param array<int, array<string, mixed>> $registros filas de asistencia del mes
     * @param array<string, string>            $feriados  fecha (Y-m-d) => nombre
     * @return array{semanas: array<int, array<int, array<string, mixed>|null>>,
     *               resumen: array{dias_asistidos: int, horas: float, sin_registro: int}}
     */
    public function construir(
        string $mes,
        array $registros,
        array $feriados,
        DateTimeImmutable $ingreso,
        DateTimeImmutable $hoy
    ): array {
        $primero    = new DateTimeImmutable($mes . '-01');
        $hoyStr     = $hoy->format('Y-m-d');
        $ingresoStr = $ingreso->format('Y-m-d');

        $porFecha = [];
        foreach ($registros as $r) {
            $porFecha[(string)$r['fecha']] = $r;
        }

        $celdas  = array_fill(0, (int)$primero->format('N') - 1, null);
        $resumen = ['dias_asistidos' => 0, 'horas' => 0.0, 'sin_registro' => 0];

        $total = (int)$primero->format('t');
        for ($n = 1; $n <= $total; $n++) {
            $dia    = $primero->setDate((int)$primero->format('Y'), (int)$primero->format('n'), $n);
            $fecha  = $dia->format('Y-m-d');
            $reg    = $porFecha[$fecha] ?? null;
            $estado = $this->estado($fecha, $reg, $feriados, $ingresoStr, $hoyStr, (int)$dia->format('N'));

            if ($estado === 'asistio') {
                $resumen['dias_asistidos']++;
                $resumen['horas'] += (float)$reg['horas_trabajadas'];
            } elseif ($estado === 'sin_registro') {
                $resumen['sin_registro']++;
            }

            $celdas[] = [
                'fecha'    => $fecha,
                'numero'   => $n,
                'estado'   => $estado,
                'es_hoy'   => $fecha === $hoyStr,
                'registro' => $reg,
                'feriado'  => $feriados[$fecha] ?? null,
            ];
        }

        while (count($celdas) % 7 !== 0) {
            $celdas[] = null;
        }
        $resumen['horas'] = round($resumen['horas'], 2);

        return ['semanas' => array_chunk($celdas, 7), 'resumen' => $resumen];
    }

    /** @param array<string, mixed> $registro */
    public static function tieneSalida(array $registro): bool
    {
        return substr((string)$registro['hora_salida'], 0, 5) !== '00:00';
    }

    /**
     * @param array<string, mixed>|null $reg
     * @param array<string, string>     $feriados
     */
    private function estado(string $fecha, ?array $reg, array $feriados, string $ingreso, string $hoy, int $diaSemana): string
    {
        if ($fecha < $ingreso) {
            return 'antes_ingreso';
        }
        if ($reg !== null && $fecha <= $hoy) {
            return self::tieneSalida($reg) ? 'asistio' : 'sin_salida';
        }
        // Feriados y domingos se muestran también en meses futuros, para planear.
        if (isset($feriados[$fecha])) {
            return 'feriado';
        }
        if ($diaSemana === 7) {
            return 'libre';
        }
        if ($fecha > $hoy) {
            return 'futuro';
        }
        return $fecha === $hoy ? 'hoy' : 'sin_registro';
    }
}
