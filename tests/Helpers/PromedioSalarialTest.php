<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\PromedioSalarial;
use PHPUnit\Framework\TestCase;

final class PromedioSalarialTest extends TestCase
{
    public function testVentanaCompletaDivideEntreTodosLosDias(): void
    {
        self::assertEqualsWithDelta(350.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', null, null), 0.001);
    }

    public function testIngresoDentroDeLaVentanaAcortaLosDias(): void
    {
        // del 06 al 10 = 5 días
        self::assertEqualsWithDelta(700.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2026-01-06', null), 0.001);
    }

    public function testUsaElMayorEntreIngresoYPrimeraNomina(): void
    {
        self::assertEqualsWithDelta(1750.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2025-06-01', '2026-01-09'), 0.001);
    }

    public function testUltimoFinDeNominaAcortaElFinal(): void
    {
        // del 01 al 05 = 5 días
        self::assertEqualsWithDelta(700.0, PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', null, null, '2026-01-05'), 0.001);
    }

    public function testRespetaElTopeDeDias(): void
    {
        self::assertEqualsWithDelta(10.0, PromedioSalarial::diario(3500.0, '2025-01-01', '2026-01-10', null, null, null, 350), 0.001);
    }

    public function testSinBrutoOSinDiasDevuelveNull(): void
    {
        self::assertNull(PromedioSalarial::diario(0.0, '2026-01-01', '2026-01-10', null, null));
        self::assertNull(PromedioSalarial::diario(3500.0, '2026-01-01', '2026-01-10', '2026-02-01', null));
    }

    public function testMensualPorPeriodosDoceQuincenasDan300mil(): void
    {
        // 01/01 al 30/06 = 180 días comerciales; 1 800 000 ÷ 180 × 30 = 300 000
        self::assertEqualsWithDelta(300000.0, PromedioSalarial::mensualPorPeriodos(1800000.0, '2026-01-01', '2026-06-30'), 0.001);
    }

    public function testMensualPorPeriodosNoDependeDeLosDiasCalendario(): void
    {
        // 01/04 al 30/09 = 183 días calendario pero 180 comerciales; 1 800 000 ÷ 180 × 30 = 300 000
        self::assertEqualsWithDelta(300000.0, PromedioSalarial::mensualPorPeriodos(1800000.0, '2026-04-01', '2026-09-30'), 0.001);
    }

    public function testMensualPorPeriodosDevuelveNullSinDatos(): void
    {
        self::assertNull(PromedioSalarial::mensualPorPeriodos(0.0, '2026-01-01', '2026-06-30'));
        self::assertNull(PromedioSalarial::mensualPorPeriodos(1800000.0, null, '2026-06-30'));
        self::assertNull(PromedioSalarial::mensualPorPeriodos(1800000.0, '2026-01-01', null));
        self::assertNull(PromedioSalarial::mensualPorPeriodos(1800000.0, '2026-07-01', '2026-06-30'));
    }
}
