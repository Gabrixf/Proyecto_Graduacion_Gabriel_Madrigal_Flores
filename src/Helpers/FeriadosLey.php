<?php

declare(strict_types=1);

namespace App\Helpers;

use DateTimeImmutable;

/**
 * FeriadosLey — feriados de Costa Rica de un año según el Art. 148 del Código de Trabajo
 * (texto vigente, Ley 10050 de 2021). Cálculo puro, sin BD ni HTTP.
 *
 * El 2 de agosto, el 31 de agosto y el 1 de diciembre son de pago no obligatorio; el resto,
 * obligatorio. El 12 de octubre dejó de ser feriado (Ley 9803 de 2020). Jueves y Viernes
 * Santo dependen del domingo de Pascua de cada año.
 */
final class FeriadosLey
{
    /** Feriados de fecha fija: mes-día => [nombre, tipo]. */
    private const FIJOS = [
        '01-01' => ['Año Nuevo', 'obligatorio_pago'],
        '04-11' => ['Día de Juan Santamaría', 'obligatorio_pago'],
        '05-01' => ['Día Internacional del Trabajador', 'obligatorio_pago'],
        '07-25' => ['Anexión del Partido de Nicoya', 'obligatorio_pago'],
        '08-02' => ['Día de la Virgen de los Ángeles', 'no_obligatorio_pago'],
        '08-15' => ['Día de la Madre', 'obligatorio_pago'],
        '08-31' => ['Día de la Persona Negra y la Cultura Afrocostarricense', 'no_obligatorio_pago'],
        '09-15' => ['Día de la Independencia', 'obligatorio_pago'],
        '12-01' => ['Día de la Abolición del Ejército', 'no_obligatorio_pago'],
        '12-25' => ['Navidad', 'obligatorio_pago'],
    ];

    /**
     * Los feriados del año ordenados por fecha.
     * @return array<int, array{fecha: string, nombre: string, tipo: string}>
     */
    public function delAnio(int $anio): array
    {
        $feriados = [];
        foreach (self::FIJOS as $mesDia => [$nombre, $tipo]) {
            $feriados[] = ['fecha' => $anio . '-' . $mesDia, 'nombre' => $nombre, 'tipo' => $tipo];
        }
        $pascua     = $this->pascua($anio);
        $feriados[] = ['fecha' => $pascua->modify('-3 days')->format('Y-m-d'), 'nombre' => 'Jueves Santo', 'tipo' => 'obligatorio_pago'];
        $feriados[] = ['fecha' => $pascua->modify('-2 days')->format('Y-m-d'), 'nombre' => 'Viernes Santo', 'tipo' => 'obligatorio_pago'];

        usort($feriados, static fn (array $a, array $b) => strcmp($a['fecha'], $b['fecha']));
        return $feriados;
    }

    /** Domingo de Pascua (calendario gregoriano, algoritmo de Meeus/Jones/Butcher). */
    public function pascua(int $anio): DateTimeImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
    }
}
