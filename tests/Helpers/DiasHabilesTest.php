<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\DiasHabiles;
use PHPUnit\Framework\TestCase;

final class DiasHabilesTest extends TestCase
{
    public function testSemanaDeLunesASabadoSonSeisDias(): void
    {
        // 2026-10-12 (lunes) a 2026-10-17 (sábado)
        self::assertSame(6, (new DiasHabiles())->contar('2026-10-12', '2026-10-17', []));
    }

    public function testElDomingoNoSeCuenta(): void
    {
        // 2026-10-12 (lunes) a 2026-10-18 (domingo)
        self::assertSame(6, (new DiasHabiles())->contar('2026-10-12', '2026-10-18', []));
    }

    public function testUnFeriadoDentroDelRangoNoSeCuenta(): void
    {
        // 15 de setiembre de 2026 cae martes
        self::assertSame(5, (new DiasHabiles())->contar('2026-09-14', '2026-09-19', ['2026-09-15']));
    }

    public function testFeriadoEnDomingoNoSeDescuentaDosVeces(): void
    {
        // 2 de agosto de 2026 cae domingo
        self::assertSame(6, (new DiasHabiles())->contar('2026-08-02', '2026-08-08', ['2026-08-02']));
    }

    public function testSoloUnFeriadoDaCero(): void
    {
        self::assertSame(0, (new DiasHabiles())->contar('2026-09-15', '2026-09-15', ['2026-09-15']));
    }

    public function testUnDiaHabilDaUno(): void
    {
        self::assertSame(1, (new DiasHabiles())->contar('2026-10-14', '2026-10-14', []));
    }

    public function testRangoInvertidoDaCero(): void
    {
        self::assertSame(0, (new DiasHabiles())->contar('2026-10-17', '2026-10-12', []));
    }
}
