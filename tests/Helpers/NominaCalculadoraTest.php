<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\NominaCalculadora;
use PHPUnit\Framework\TestCase;

final class NominaCalculadoraTest extends TestCase
{
    private function calc(): NominaCalculadora
    {
        return new NominaCalculadora(0.1067, 0.2633);
    }

    public function testValorHora(): void
    {
        // 240000 / 240 = 1000
        self::assertEqualsWithDelta(1000.0, $this->calc()->valorHora(240000.0), 0.001);
    }

    public function testMontoHorasExtraOrdinaria(): void
    {
        // 1000 * 3h * 1.5 = 4500
        self::assertEqualsWithDelta(4500.0, $this->calc()->montoHorasExtra(240000.0, 3.0, 1.5), 0.001);
    }

    public function testMontoHorasExtraFeriado(): void
    {
        // 1000 * 3h * 2.0 = 6000
        self::assertEqualsWithDelta(6000.0, $this->calc()->montoHorasExtra(240000.0, 3.0, 2.0), 0.001);
    }

    public function testDeduccionesCCSSDevuelveObreraYPatronal(): void
    {
        $ded = $this->calc()->deduccionesCCSS(124500.0);
        self::assertCount(2, $ded);
        self::assertSame('CCSS_empleado', $ded[0]['tipo']);
        self::assertFalse($ded[0]['informativa']);
        self::assertEqualsWithDelta(13284.15, $ded[0]['monto'], 0.01); // 124500 * 0.1067
        self::assertSame('CCSS_patronal', $ded[1]['tipo']);
        self::assertTrue($ded[1]['informativa']);
        self::assertEqualsWithDelta(32780.85, $ded[1]['monto'], 0.01); // 124500 * 0.2633
    }

    public function testCalcularTotalesExcluyeInformativaDelNeto(): void
    {
        $ingresos    = [['monto' => 120000.0], ['monto' => 4500.0]];
        $deducciones = $this->calc()->deduccionesCCSS(124500.0);
        $t = $this->calc()->calcularTotales($ingresos, $deducciones);

        self::assertEqualsWithDelta(124500.0, $t['salario_bruto'], 0.01);
        self::assertEqualsWithDelta(13284.15, $t['total_deducciones'], 0.01); // solo obrera
        self::assertEqualsWithDelta(111215.85, $t['salario_neto'], 0.01);     // bruto - obrera
    }
}
