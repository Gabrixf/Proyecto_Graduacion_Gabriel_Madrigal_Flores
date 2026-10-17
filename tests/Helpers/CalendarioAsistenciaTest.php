<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\CalendarioAsistencia;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CalendarioAsistenciaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function reg(string $fecha, string $salida = '17:00:00', string $horas = '9.00'): array
    {
        return ['id_asistencia' => 1, 'fecha' => $fecha, 'hora_entrada' => '08:00:00',
                'hora_salida' => $salida, 'horas_trabajadas' => $horas, 'salida_manual' => 0];
    }

    /** @return array<string, array<string, mixed>> fecha => día */
    private function dias(array $cal): array
    {
        $out = [];
        foreach ($cal['semanas'] as $semana) {
            foreach ($semana as $dia) {
                if ($dia !== null) {
                    $out[$dia['fecha']] = $dia;
                }
            }
        }
        return $out;
    }

    private function octubre(array $registros = [], array $feriados = [], string $ingreso = '2020-01-01', array $descanso = [7]): array
    {
        return (new CalendarioAsistencia())->construir(
            '2026-10', $registros, $feriados,
            new DateTimeImmutable($ingreso), new DateTimeImmutable('2026-10-12'), $descanso
        );
    }

    public function testOctubreEmpiezaEnJuevesConTresCasillasVacias(): void
    {
        $cal = $this->octubre();

        self::assertCount(5, $cal['semanas']);
        self::assertSame([null, null, null], array_slice($cal['semanas'][0], 0, 3));
        self::assertSame(1, $cal['semanas'][0][3]['numero']);
        foreach ($cal['semanas'] as $semana) {
            self::assertCount(7, $semana);
        }
    }

    public function testElSabadoEsLibreSiElHorarioNoLoIncluye(): void
    {
        $dias = $this->dias($this->octubre([], [], '2020-01-01', [6, 7]));
        self::assertSame('libre', $dias['2026-10-03']['estado']);
        self::assertSame('libre', $dias['2026-10-04']['estado']);
    }

    public function testFebreroQueEmpiezaEnDomingo(): void
    {
        $cal = (new CalendarioAsistencia())->construir(
            '2026-02', [], [], new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2026-10-12')
        );
        $dias = $this->dias($cal);

        self::assertCount(28, $dias);
        self::assertSame(1, $cal['semanas'][0][6]['numero']);
        self::assertSame('libre', $dias['2026-02-01']['estado']);
    }

    public function testEstadosBasicos(): void
    {
        $dias = $this->dias($this->octubre(
            [$this->reg('2026-10-01'), $this->reg('2026-10-02', '00:00:00', '0.00')],
            ['2026-10-15' => 'Día de las Culturas']
        ));

        self::assertSame('asistio', $dias['2026-10-01']['estado']);
        self::assertSame('sin_salida', $dias['2026-10-02']['estado']);
        self::assertSame('sin_registro', $dias['2026-10-03']['estado']);   // sábado hábil
        self::assertSame('libre', $dias['2026-10-04']['estado']);          // domingo
        self::assertSame('hoy', $dias['2026-10-12']['estado']);
        self::assertTrue($dias['2026-10-12']['es_hoy']);
        self::assertSame('futuro', $dias['2026-10-13']['estado']);
        self::assertSame('feriado', $dias['2026-10-15']['estado']);       // un feriado futuro ya se ve
        self::assertSame('libre', $dias['2026-10-18']['estado']);         // domingo futuro
    }

    public function testFeriadoPasadoSinRegistro(): void
    {
        $dias = $this->dias($this->octubre([], ['2026-10-05' => 'Prueba']));

        self::assertSame('feriado', $dias['2026-10-05']['estado']);
        self::assertSame('Prueba', $dias['2026-10-05']['feriado']);
    }

    public function testRegistroGanaAlFeriado(): void
    {
        $dias = $this->dias($this->octubre([$this->reg('2026-10-05')], ['2026-10-05' => 'Prueba']));

        self::assertSame('asistio', $dias['2026-10-05']['estado']);
    }

    public function testIngresoAMitadDeMes(): void
    {
        $dias = $this->dias($this->octubre([], [], '2026-10-08'));

        self::assertSame('antes_ingreso', $dias['2026-10-07']['estado']);
        self::assertSame('sin_registro', $dias['2026-10-08']['estado']);
    }

    public function testHoyConEntradaSinSalidaEsSinSalida(): void
    {
        $dias = $this->dias($this->octubre([$this->reg('2026-10-12', '00:00:00', '0.00')]));

        self::assertSame('sin_salida', $dias['2026-10-12']['estado']);
        self::assertTrue($dias['2026-10-12']['es_hoy']);
    }

    public function testResumenCuentaSoloDiasCompletosYDiasSinRegistro(): void
    {
        $cal = $this->octubre(
            [$this->reg('2026-10-01', '17:00:00', '9.00'), $this->reg('2026-10-02', '12:00:00', '4.50'),
             $this->reg('2026-10-05', '00:00:00', '0.00')],
            [], '2026-10-01'
        );

        self::assertSame(2, $cal['resumen']['dias_asistidos']);
        self::assertSame(13.5, $cal['resumen']['horas']);
        // Hábiles pasados sin registro entre el 1 y el 11: 3, 6, 7, 8, 9, 10 = 6 (el 4 y el 11 son domingo).
        self::assertSame(6, $cal['resumen']['sin_registro']);
    }

    public function testTieneSalida(): void
    {
        self::assertTrue(CalendarioAsistencia::tieneSalida(['hora_salida' => '17:00:00']));
        self::assertFalse(CalendarioAsistencia::tieneSalida(['hora_salida' => '00:00:00']));
    }
}
