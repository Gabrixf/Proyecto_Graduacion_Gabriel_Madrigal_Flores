<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\LiquidacionCalculadora;
use PHPUnit\Framework\TestCase;

final class LiquidacionCalculadoraTest extends TestCase
{
    private function calc(): LiquidacionCalculadora
    {
        return new LiquidacionCalculadora();
    }

    public function testDiasPreavisoPorAntiguedad(): void
    {
        $c = $this->calc();
        self::assertSame(0,  $c->diasPreaviso(2));   // < 3 meses
        self::assertSame(7,  $c->diasPreaviso(4));   // 3-6 meses
        self::assertSame(15, $c->diasPreaviso(8));   // 6-12 meses
        self::assertSame(30, $c->diasPreaviso(24));  // > 1 año
    }

    public function testDiasCesantiaUnAnio(): void
    {
        // 1 año exacto => 19.5 días
        self::assertEqualsWithDelta(19.5, $this->calc()->diasCesantia(1, 0.0, 12), 0.001);
    }

    public function testDiasCesantiaOchoAniosSumaTabla(): void
    {
        // 8 años => suma tabla 1..8 = 167.74 días
        self::assertEqualsWithDelta(167.74, $this->calc()->diasCesantia(8, 0.0, 96), 0.001);
    }

    public function testDiasCesantiaTopeOchoAnios(): void
    {
        // 10 años => tope en 8 años => 167.74 días
        self::assertEqualsWithDelta(167.74, $this->calc()->diasCesantia(10, 0.0, 120), 0.001);
    }

    public function testDiasCesantiaConFraccion(): void
    {
        // 5 años 6 meses => suma 1..5 (102.24) + tabla[6]*0.5 (21.5*0.5=10.75) = 112.99
        self::assertEqualsWithDelta(112.99, $this->calc()->diasCesantia(5, 0.5, 66), 0.001);
    }

    public function testCesantiaMinimoTresMeses(): void
    {
        // < 3 meses => sin cesantía
        self::assertEqualsWithDelta(0.0, $this->calc()->diasCesantia(0, 0.16, 2), 0.001);
    }

    public function testMontoVacaciones(): void
    {
        // valorDia = 300000/30 = 10000; 10 días => 100000
        self::assertEqualsWithDelta(100000.0, $this->calc()->montoVacaciones(300000.0, 10.0), 0.01);
    }

    public function testCalcularDespidoConCausaSinPreavisoNiCesantia(): void
    {
        $r = $this->calc()->calcular([
            'salarioMensual' => 300000.0,
            'fechaIngreso'   => '2020-01-01',
            'fechaSalida'    => '2026-07-01',
            'motivo'         => 'despido_con_causa',
            'diasVacaciones' => 10.0,
        ]);
        self::assertEqualsWithDelta(0.0, $r['preaviso'], 0.01);
        self::assertEqualsWithDelta(0.0, $r['cesantia'], 0.01);
        self::assertGreaterThan(0.0, $r['vacaciones_pendientes']);
        self::assertGreaterThan(0.0, $r['aguinaldo_proporcional']);
        self::assertEqualsWithDelta(
            $r['preaviso'] + $r['cesantia'] + $r['vacaciones_pendientes'] + $r['aguinaldo_proporcional'],
            $r['total_liquidacion'],
            0.01
        );
    }

    public function testCalcularDespidoSinCausaIncluyePreavisoYCesantia(): void
    {
        $r = $this->calc()->calcular([
            'salarioMensual' => 300000.0,
            'fechaIngreso'   => '2020-01-01',
            'fechaSalida'    => '2026-07-01',
            'motivo'         => 'despido_sin_causa',
            'diasVacaciones' => 10.0,
        ]);
        self::assertGreaterThan(0.0, $r['preaviso']);
        self::assertGreaterThan(0.0, $r['cesantia']);
        self::assertEqualsWithDelta(
            $r['preaviso'] + $r['cesantia'] + $r['vacaciones_pendientes'] + $r['aguinaldo_proporcional'],
            $r['total_liquidacion'],
            0.01
        );
    }
}
