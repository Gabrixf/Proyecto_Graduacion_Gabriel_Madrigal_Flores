<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\LiquidacionCalculadora;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LiquidacionCalculadoraTest extends TestCase
{
    private function calc(): LiquidacionCalculadora
    {
        return new LiquidacionCalculadora();
    }

    /** @return array<string, array{string, string, float, float}> ingreso, salida, preaviso, cesantía */
    public static function antiguedades(): array
    {
        return [
            'mismo día'          => ['2026-01-01', '2026-01-01', 0.0, 0.0],
            '2 meses'            => ['2026-01-01', '2026-03-01', 0.0, 0.0],
            '3 meses exactos'    => ['2026-01-01', '2026-04-01', 7.0, 7.0],
            '6 meses exactos'    => ['2026-01-01', '2026-07-01', 7.0, 7.0],
            '6 meses y 1 día'    => ['2026-01-01', '2026-07-02', 15.0, 14.0],
            '11 meses'           => ['2025-01-01', '2025-12-01', 15.0, 14.0],
            '1 año exacto'       => ['2025-01-01', '2026-01-01', 30.0, 19.5],
            '1 año y 8 meses'    => ['2024-01-01', '2025-09-01', 30.0, 39.0],
            '6 años y 6 meses'   => ['2020-01-01', '2026-07-01', 30.0, 129.0],
            '6 años y 7 meses'   => ['2020-01-01', '2026-08-01', 30.0, 150.5],
            '9 años'             => ['2017-01-01', '2026-01-01', 30.0, 176.0],
            '12 años'            => ['2014-01-01', '2026-01-01', 30.0, 164.0],
            '15 años'            => ['2011-01-01', '2026-01-01', 30.0, 160.0],
        ];
    }

    #[DataProvider('antiguedades')]
    public function testDiasDePreavisoYCesantiaSegunAntiguedad(string $ingreso, string $salida, float $preaviso, float $cesantia): void
    {
        $c   = $this->calc();
        $ant = $c->antiguedad($ingreso, $salida);
        self::assertEqualsWithDelta($preaviso, $c->diasPreaviso($ant), 0.001);
        self::assertEqualsWithDelta($cesantia, $c->diasCesantia($ant)['dias'], 0.001);
    }

    public function testCesantiaUsaUnaSolaTasaPorLaAntiguedadTotal(): void
    {
        // DAJ-AE-083-09: 1 año y 8 meses = 19,5 × 2, no 19,5 + 20
        $r = $this->calc()->diasCesantia($this->calc()->antiguedad('2024-01-01', '2025-09-01'));
        self::assertSame(2, $r['anios_contados']);
        self::assertEqualsWithDelta(19.5, $r['tasa'], 0.001);
    }

    public function testTasaDeLaTablaCompletaDelArticulo29(): void
    {
        $c = $this->calc();
        $esperado = [1 => 19.5, 2 => 20.0, 3 => 20.5, 4 => 21.0, 5 => 21.24, 6 => 21.5, 7 => 22.0,
                     8 => 22.0, 9 => 22.0, 10 => 21.5, 11 => 21.0, 12 => 20.5, 13 => 20.0, 20 => 20.0];
        foreach ($esperado as $anios => $tasa) {
            self::assertEqualsWithDelta($tasa, $c->tasaCesantia($anios), 0.001, "año $anios");
        }
    }

    /** @return array<string, array{string, bool, bool}> motivo, paga preaviso, paga cesantía */
    public static function motivos(): array
    {
        return [
            'despido sin causa'     => ['despido_sin_causa', true, true],
            'renuncia justificada'  => ['renuncia_justificada', true, true],
            'muerte'                => ['muerte', false, true],
            'jubilación'            => ['jubilacion', false, true],
            'renuncia'              => ['renuncia', false, false],
            'despido con causa'     => ['despido_con_causa', false, false],
            'mutuo acuerdo'         => ['mutuo_acuerdo', false, false],
        ];
    }

    #[DataProvider('motivos')]
    public function testRubrosSegunElMotivo(string $motivo, bool $preaviso, bool $cesantia): void
    {
        $r = $this->calc()->calcular($this->datos(['motivo' => $motivo]));
        self::assertSame($preaviso, $r['preaviso'] > 0, 'preaviso');
        self::assertSame($cesantia, $r['cesantia'] > 0, 'cesantía');
        self::assertGreaterThan(0, $r['vacaciones_pendientes']);
        self::assertGreaterThan(0, $r['aguinaldo_proporcional']);
    }

    public function testCalcularDespidoSinCausaIncluyePreavisoYCesantia(): void
    {
        // 6 años y 6 meses, base ₡300 000 (₡10 000 por día)
        $r = $this->calc()->calcular($this->datos());

        self::assertEqualsWithDelta(300000.0, $r['preaviso'], 0.01);        // 30 días
        self::assertEqualsWithDelta(1290000.0, $r['cesantia'], 0.01);       // 6 × 21,5 = 129 días
        self::assertEqualsWithDelta(100000.0, $r['vacaciones_pendientes'], 0.01);
        self::assertEqualsWithDelta(175000.0, $r['aguinaldo_proporcional'], 0.01);
        self::assertEqualsWithDelta(1865000.0, $r['total_liquidacion'], 0.01);
        self::assertEqualsWithDelta(30.0, $r['dias_preaviso'], 0.001);
        self::assertEqualsWithDelta(129.0, $r['dias_cesantia'], 0.001);
        self::assertEqualsWithDelta(10.0, $r['dias_vacaciones'], 0.001);
        self::assertEqualsWithDelta(300000.0, $r['salario_promedio'], 0.01);
        self::assertSame(6, $r['anios_contados']);
        self::assertEqualsWithDelta(21.5, $r['tasa_cesantia'], 0.001);
    }

    public function testVacacionesNegativasSePaganComoCero(): void
    {
        $r = $this->calc()->calcular($this->datos(['diasVacaciones' => -3.0]));
        self::assertEqualsWithDelta(0.0, $r['vacaciones_pendientes'], 0.001);
        self::assertEqualsWithDelta(0.0, $r['dias_vacaciones'], 0.001);
    }

    public function testDiasComerciales(): void
    {
        self::assertSame(15, LiquidacionCalculadora::diasComerciales('2026-08-16', '2026-08-31')); // el 31 no cuenta
        self::assertSame(30, LiquidacionCalculadora::diasComerciales('2026-02-01', '2026-02-28')); // febrero completo = 30
        self::assertSame(15, LiquidacionCalculadora::diasComerciales('2028-02-16', '2028-02-29')); // bisiesto
        self::assertSame(180, LiquidacionCalculadora::diasComerciales('2026-03-01', '2026-08-31'));
        self::assertSame(16, LiquidacionCalculadora::diasComerciales('2026-06-16', '2026-07-01'));
        self::assertSame(0, LiquidacionCalculadora::diasComerciales('2026-08-31', '2026-08-31'));  // solo un día 31
        self::assertSame(0, LiquidacionCalculadora::diasComerciales('2026-09-02', '2026-09-01'));
    }

    /** @param array<string, mixed> $cambios @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'fechaIngreso'            => '2020-01-01',
            'fechaSalida'             => '2026-07-01',
            'motivo'                  => 'despido_sin_causa',
            'salarioPromedio'         => 300000.0,
            'salarioDiarioVacaciones' => 10000.0,
            'diasVacaciones'          => 10.0,
            'brutosAguinaldo'         => 2100000.0,
        ];
    }
}
