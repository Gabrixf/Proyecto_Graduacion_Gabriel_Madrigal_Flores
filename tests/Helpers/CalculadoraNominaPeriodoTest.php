<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\CalculadoraNominaPeriodo;
use App\Helpers\NominaCalculadora;
use PHPUnit\Framework\TestCase;

final class CalculadoraNominaPeriodoTest extends TestCase
{
    private const TASAS = ['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683];
    private const TABLA = [
        'tramos' => [['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
                     ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25]],
        'credito_hijo' => 1710.0, 'credito_conyuge' => 2590.0,
    ];

    private function c(): CalculadoraNominaPeriodo
    {
        return new CalculadoraNominaPeriodo(new NominaCalculadora());
    }

    /** Quincena 1–15 de setiembre 2026, salario 480 000 (diario 16 000). */
    private function base(array $cambios = []): array
    {
        return $cambios + [
            'salario_mensual' => 480000.0, 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-15',
            'incapacidades' => [], 'permisos_sin_goce' => [], 'dias_vacaciones' => 0.0,
            'promedio_diario_50_semanas' => 16000.0, 'lineas_adicionales' => [], 'tasas' => self::TASAS, 'renta' => null,
        ];
    }

    private function linea(array $r, string $tipo): ?array
    {
        foreach ($r['ingresos'] as $i) { if ($i['tipo'] === $tipo) { return $i; } }
        foreach ($r['deducciones'] as $d) { if ($d['tipo'] === $tipo) { return $d; } }
        return null;
    }

    public function testQuincenaSinAusencias(): void
    {
        $r = $this->c()->calcular($this->base());
        self::assertSame(240000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame('Salario quincenal: 15 de 15 días', $this->linea($r, 'salario_base')['descripcion']);
        self::assertSame(25992.0, $this->linea($r, 'CCSS_empleado')['monto']);   // 240 000 × 10.83 %
        self::assertSame(214008.0, $r['totales']['salario_neto']);
    }

    public function testIncapacidadCcssDeCincoDias(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-12', 'reincapacidad' => false],
        ]]));
        self::assertSame(160000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(24000.0, $this->linea($r, 'subsidio_incapacidad')['monto']);   // 3 × 8 000
        self::assertSame(17328.0, $this->linea($r, 'CCSS_empleado')['monto']);         // solo sobre 160 000
        self::assertSame(10.0, $r['dias_laborados']);
    }

    public function testIncapacidadQueCruzaPeriodosPagaSoloElDiaTresEnLaSegunda(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'incapacidades' => [['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-18', 'reincapacidad' => false]],
        ]));
        self::assertSame(8000.0, $this->linea($r, 'subsidio_incapacidad')['monto']);   // solo el 16 (día 3)
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);         // 15 − 3 días (16, 17, 18)
    }

    public function testReincapacidadNoTieneSubsidioPatronal(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'reincapacidad' => true],
        ]]));
        self::assertNull($this->linea($r, 'subsidio_incapacidad'));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);
    }

    public function testIncapacidadInsYParticularSoloRebajan(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'INS', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-02', 'reincapacidad' => false],
            ['tipo' => 'particular', 'fecha_inicio' => '2026-09-10', 'fecha_fin' => '2026-09-10', 'reincapacidad' => false],
        ]]));
        self::assertNull($this->linea($r, 'subsidio_incapacidad'));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);   // 12 días
    }

    public function testPermisoSinGoceDeUnDia(): void
    {
        $r = $this->c()->calcular($this->base(['permisos_sin_goce' => [['fecha_inicio' => '2026-09-10', 'fecha_fin' => '2026-09-10']]]));
        self::assertSame(224000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame('Salario quincenal: 14 de 15 días', $this->linea($r, 'salario_base')['descripcion']);
    }

    public function testPermisoQueCubreTodaLaQuincenaDejaCeroDias(): void
    {
        $r = $this->c()->calcular($this->base(['permisos_sin_goce' => [['fecha_inicio' => '2026-08-28', 'fecha_fin' => '2026-09-20']]]));
        self::assertSame(0.0, $r['dias_laborados']);
        self::assertSame(0.0, $this->linea($r, 'salario_base')['monto']);
    }

    public function testPermisoQueCruzaUnDomingoRebajaDiasCalendario(): void
    {
        $r = $this->c()->calcular($this->base(['permisos_sin_goce' => [['fecha_inicio' => '2026-09-05', 'fecha_fin' => '2026-09-07']]]));   // sáb-dom-lun
        self::assertSame(12.0, $r['dias_laborados']);
    }

    public function testIncapacidadesTraslapadasNoCuentanDosVeces(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'INS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-10', 'reincapacidad' => false],
            ['tipo' => 'INS', 'fecha_inicio' => '2026-09-09', 'fecha_fin' => '2026-09-12', 'reincapacidad' => false],
        ]]));
        self::assertSame(10.0, $r['dias_laborados']);   // 5 días de incapacidad, no 7
    }

    public function testIncapacidadMasVacacionesNoExcedenLaQuincena(): void
    {
        $r = $this->c()->calcular($this->base([
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-10', 'reincapacidad' => false]],
            'dias_vacaciones' => 10.0,
        ]));
        self::assertSame(0.0, $r['dias_laborados']);
        $vac = array_values(array_filter($r['ingresos'], fn ($i) => $i['tipo'] === 'vacaciones'));
        self::assertCount(1, $vac);
        self::assertSame(80000.0, $vac[0]['monto']);   // 5 días × 16 000
        self::assertSame('Vacaciones: 5 días', $vac[0]['descripcion']);
    }

    public function testPermisoDentroDeIncapacidadNoSeCuentaDosVeces(): void
    {
        $r = $this->c()->calcular($this->base([
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-09-08', 'fecha_fin' => '2026-09-12', 'reincapacidad' => false]],
            'permisos_sin_goce' => [['fecha_inicio' => '2026-09-10', 'fecha_fin' => '2026-09-11']],
        ]));
        self::assertSame(10.0, $r['dias_laborados']);
    }

    public function testVacacionesSinDiferencia(): void
    {
        $r = $this->c()->calcular($this->base(['dias_vacaciones' => 3.0]));
        self::assertSame(192000.0, $this->linea($r, 'salario_base')['monto']);
        $vac = array_values(array_filter($r['ingresos'], fn ($i) => $i['tipo'] === 'vacaciones'));
        self::assertCount(1, $vac);
        self::assertSame(48000.0, $vac[0]['monto']);
        self::assertSame(240000.0, $r['totales']['salario_bruto']);
    }

    public function testVacacionesConDiferenciaArt157(): void
    {
        $r = $this->c()->calcular($this->base(['dias_vacaciones' => 3.0, 'promedio_diario_50_semanas' => 18000.0]));
        $vac = array_values(array_filter($r['ingresos'], fn ($i) => $i['tipo'] === 'vacaciones'));
        self::assertCount(2, $vac);
        self::assertSame('Diferencia de vacaciones (Art. 157)', $vac[1]['descripcion']);
        self::assertSame(6000.0, $vac[1]['monto']);   // 3 × (18 000 − 16 000)
    }

    public function testAusenciasDeMasDeQuinceDiasDejanCeroDiasLaborados(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31',
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-10-10', 'fecha_fin' => '2026-11-05', 'reincapacidad' => false]],
        ]));
        self::assertSame(0.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(0.0, $r['dias_laborados']);
    }

    public function testLasLineasAdicionalesCotizan(): void
    {
        $r = $this->c()->calcular($this->base(['lineas_adicionales' => [
            ['tipo' => 'horas_extra', 'descripcion' => 'Horas extra 10/09/2026: 2.00 h × 1.50', 'monto' => 6000.0],
        ]]));
        self::assertSame(26641.8, $this->linea($r, 'CCSS_empleado')['monto']);   // 246 000 × 10.83 %
    }

    public function testRentaSobreUnMillonAlMes(): void
    {
        self::assertSame(8200.0, $this->c()->impuestoRenta(1000000.0, self::TABLA, 0, false));
    }

    public function testRentaConDosHijosYConyuge(): void
    {
        self::assertSame(2190.0, $this->c()->impuestoRenta(1000000.0, self::TABLA, 2, true));
    }

    public function testRentaBajoElExentoEsCero(): void
    {
        self::assertSame(0.0, $this->c()->impuestoRenta(900000.0, self::TABLA, 0, false));
        self::assertSame(0.0, $this->c()->impuestoRenta(919000.0, self::TABLA, 1, false));   // 100 − 1 710 → 0
    }

    public function testRentaTodosLosTramos(): void
    {
        // 429 000 × 10 % + 1 017 000 × 15 % + 2 363 000 × 20 % + 273 000 × 25 %
        self::assertSame(42900.0 + 152550.0 + 472600.0 + 68250.0, $this->c()->impuestoRenta(5000000.0, self::TABLA, 0, false));
    }

    public function testSegundaQuincenaRetieneRentaSobreElMesYElSubsidioNoTributa(): void
    {
        $r = $this->c()->calcular($this->base([
            'salario_mensual' => 1000000.0, 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'renta' => ['tabla' => self::TABLA, 'hijos' => 0, 'conyuge' => false, 'gravable_primera_quincena' => 500000.0],
        ]));
        self::assertSame(8200.0, $this->linea($r, 'impuesto_renta')['monto']);

        $conSubsidio = $this->c()->calcular($this->base([
            'salario_mensual' => 1000000.0, 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-30',
            'incapacidades' => [['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-18', 'reincapacidad' => false]],
            'renta' => ['tabla' => self::TABLA, 'hijos' => 0, 'conyuge' => false, 'gravable_primera_quincena' => 500000.0],
        ]));
        // gravable 2.ª: el código redondea 12 × (1 000 000 / 30) = 400 000.00; mes 900 000 → bajo el exento
        self::assertNull($this->linea($conSubsidio, 'impuesto_renta'));
    }

    public function testPrimeraQuincenaNoRetieneRenta(): void
    {
        $r = $this->c()->calcular($this->base(['salario_mensual' => 3000000.0]));
        self::assertNull($this->linea($r, 'impuesto_renta'));
    }

    public function testCotizableYGravable(): void
    {
        self::assertFalse(CalculadoraNominaPeriodo::esCotizable('subsidio_incapacidad'));
        self::assertFalse(CalculadoraNominaPeriodo::esGravable('subsidio_incapacidad'));
        self::assertTrue(CalculadoraNominaPeriodo::esCotizable('vacaciones'));
        self::assertTrue(CalculadoraNominaPeriodo::esGravable('horas_extra'));
    }

    public function testIncapacidadEnLimiteDeLaPrimeraQuincena(): void
    {
        $r = $this->c()->calcular($this->base(['incapacidades' => [
            ['tipo' => 'CCSS', 'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-18', 'reincapacidad' => false],
        ]]));
        self::assertSame(16000.0, $this->linea($r, 'subsidio_incapacidad')['monto']);   // días 14 y 15
        self::assertSame(208000.0, $this->linea($r, 'salario_base')['monto']);          // 13 × 16 000
    }

    public function testFebreroCompletoSegundaQuincenaDejaCeroDiasLaborados(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-02-16', 'fecha_fin' => '2026-02-28',
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-02-16', 'fecha_fin' => '2026-02-28', 'reincapacidad' => false]],
        ]));
        self::assertSame(0.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(0.0, $r['dias_laborados']);
    }

    public function testMesDe31DiasNoCuentaElDia31(): void
    {
        $r = $this->c()->calcular($this->base([
            'fecha_inicio' => '2026-10-16', 'fecha_fin' => '2026-10-31',
            'incapacidades' => [['tipo' => 'INS', 'fecha_inicio' => '2026-10-17', 'fecha_fin' => '2026-10-31', 'reincapacidad' => false]],
        ]));
        self::assertSame(16000.0, $this->linea($r, 'salario_base')['monto']);
        self::assertSame(1.0, $r['dias_laborados']);
    }
}
