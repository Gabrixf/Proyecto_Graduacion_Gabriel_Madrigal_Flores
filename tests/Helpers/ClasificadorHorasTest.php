<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\ClasificadorHoras;
use PHPUnit\Framework\TestCase;

final class ClasificadorHorasTest extends TestCase
{
    private const HORARIO = ['entrada' => '08:00:00', 'salida' => '17:00:00', 'almuerzo' => 60];

    private function c(): ClasificadorHoras
    {
        return new ClasificadorHoras(1.50, 3.00);
    }

    public function testDiaNormalConSalidaTardeEsExtraAUnoYMedio(): void
    {
        $r = $this->c()->clasificar('07:55:00', '18:40:00', self::HORARIO, false);
        self::assertSame('normal', $r['tipo_dia']);
        self::assertSame(1.67, $r['horas_extra']);
        self::assertSame(1.5, $r['factor_extra']);
        self::assertSame(0.0, $r['horas_dia_especial']);
        self::assertSame(9.08, $r['horas_ordinarias']);
    }

    public function testCatorceMinutosNoCuentan(): void
    {
        self::assertSame(0.0, $this->c()->clasificar('08:00:00', '17:14:00', self::HORARIO, false)['horas_extra']);
    }

    public function testQuinceMinutosSiCuentan(): void
    {
        self::assertSame(0.25, $this->c()->clasificar('08:00:00', '17:15:00', self::HORARIO, false)['horas_extra']);
    }

    public function testSalidaExactaNoTieneExtra(): void
    {
        $r = $this->c()->clasificar('08:00:00', '17:00:00', self::HORARIO, false);
        self::assertSame(0.0, $r['horas_extra']);
        self::assertSame(9.0, $r['horas_ordinarias']);
    }

    public function testFeriadoConHorarioPagaEspecialYExtraTriple(): void
    {
        $r = $this->c()->clasificar('08:00:00', '19:00:00', self::HORARIO, true);
        self::assertSame('feriado', $r['tipo_dia']);
        self::assertSame(8.0, $r['horas_dia_especial']);
        self::assertSame(2.0, $r['horas_extra']);
        self::assertSame(3.0, $r['factor_extra']);
    }

    public function testFeriadoLlegandoTempranoNoPagaMasQueLaJornadaProgramada(): void
    {
        $r = $this->c()->clasificar('06:00:00', '17:00:00', self::HORARIO, true);
        self::assertSame(8.0, $r['horas_dia_especial']);
        self::assertSame(0.0, $r['horas_extra']);
    }

    public function testDescansoTrabajadoOchoEspecialesYElRestoExtra(): void
    {
        $r = $this->c()->clasificar('08:00:00', '18:30:00', null, false);
        self::assertSame('descanso', $r['tipo_dia']);
        self::assertSame(8.0, $r['horas_dia_especial']);
        self::assertSame(2.5, $r['horas_extra']);
        self::assertSame(3.0, $r['factor_extra']);
        self::assertSame(0.0, $r['horas_ordinarias']);
    }

    public function testFeriadoEnDiaSinHorarioSeTrataComoDescansoPeroEsFeriado(): void
    {
        $r = $this->c()->clasificar('09:00:00', '13:00:00', null, true);
        self::assertSame('feriado', $r['tipo_dia']);
        self::assertSame(4.0, $r['horas_dia_especial']);
        self::assertSame(0.0, $r['horas_extra']);
    }

    public function testMasDeDoceHorasMarcaElAviso(): void
    {
        self::assertTrue($this->c()->clasificar('06:00:00', '18:30:00', self::HORARIO, false)['excede_12h']);
        self::assertFalse($this->c()->clasificar('08:00:00', '18:00:00', self::HORARIO, false)['excede_12h']);
    }

    public function testSalidaAntesDeLaEntradaNoDaHorasNegativas(): void
    {
        $r = $this->c()->clasificar('08:00:00', '07:00:00', self::HORARIO, false);
        self::assertSame(0.0, $r['horas_extra']);
        self::assertSame(0.0, $r['horas_trabajadas']);
        self::assertSame(0.0, $r['horas_ordinarias']);
    }

    public function testAceptaHorasSinSegundos(): void
    {
        self::assertSame(1.0, $this->c()->clasificar('08:00', '18:00', self::HORARIO, false)['horas_extra']);
    }
}
