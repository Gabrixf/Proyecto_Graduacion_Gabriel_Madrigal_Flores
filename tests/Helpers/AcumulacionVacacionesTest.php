<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\AcumulacionVacaciones;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AcumulacionVacacionesTest extends TestCase
{
    private function f(string $fecha): DateTimeImmutable
    {
        return new DateTimeImmutable($fecha);
    }

    public function testSinMesesCompletosNoGanaNada(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertSame(0.0, $a->diasGanados($this->f('2026-03-15'), $this->f('2026-04-14')));
    }

    public function testGanaUnDiaAlCumplirElMesExacto(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertSame(1.0, $a->diasGanados($this->f('2026-03-15'), $this->f('2026-04-15')));
    }

    public function testGanaUnDiaPorCadaMesCompleto(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertSame(12.0, $a->diasGanados($this->f('2025-01-10'), $this->f('2026-01-10')));
        self::assertSame(11.0, $a->diasGanados($this->f('2025-01-10'), $this->f('2026-01-09')));
    }

    public function testIngresoEl31CuentaElMesCompletoAlUltimoDiaDelMesCorto(): void
    {
        // 31-ene → el mes se cumple el 28-feb (último día de febrero), no el 3-mar.
        $a = new AcumulacionVacaciones();
        self::assertSame(1.0, $a->diasGanados($this->f('2026-01-31'), $this->f('2026-02-28')));
        self::assertSame(0.0, $a->diasGanados($this->f('2026-01-31'), $this->f('2026-02-27')));
    }

    public function testFechaAnteriorAlIngresoNoGanaNada(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertSame(0.0, $a->diasGanados($this->f('2026-03-15'), $this->f('2026-01-01')));
    }

    public function testFinDePruebaSonTresMesesDespuesDelIngreso(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertSame('2026-06-15', $a->finPrueba($this->f('2026-03-15'))->format('Y-m-d'));
        self::assertSame('2026-04-30', $a->finPrueba($this->f('2026-01-31'))->format('Y-m-d'));
    }

    public function testEnPruebaHastaElDiaAnteriorAlFin(): void
    {
        $a = new AcumulacionVacaciones();
        self::assertTrue($a->enPrueba($this->f('2026-03-15'), $this->f('2026-06-14')));
        self::assertFalse($a->enPrueba($this->f('2026-03-15'), $this->f('2026-06-15')));
    }

    public function testAlTerminarLaPruebaYaHayTresDiasGanados(): void
    {
        $a = new AcumulacionVacaciones();
        $ingreso = $this->f('2026-03-15');
        self::assertSame(3.0, $a->diasGanados($ingreso, $a->finPrueba($ingreso)));
    }

    public function testParametrosPersonalizados(): void
    {
        $a = new AcumulacionVacaciones(mesesPrueba: 1, diasPorMes: 1.25);
        self::assertSame('2026-04-15', $a->finPrueba($this->f('2026-03-15'))->format('Y-m-d'));
        self::assertSame(2.5, $a->diasGanados($this->f('2026-01-01'), $this->f('2026-03-01')));
    }
}
