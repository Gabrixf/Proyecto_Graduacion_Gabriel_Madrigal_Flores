<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\FeriadosLey;
use PHPUnit\Framework\TestCase;

final class FeriadosLeyTest extends TestCase
{
    public function testDomingoDePascua(): void
    {
        $calc = new FeriadosLey();
        self::assertSame('2025-04-20', $calc->pascua(2025)->format('Y-m-d'));
        self::assertSame('2026-04-05', $calc->pascua(2026)->format('Y-m-d'));
        self::assertSame('2027-03-28', $calc->pascua(2027)->format('Y-m-d'));
        self::assertSame('2028-04-16', $calc->pascua(2028)->format('Y-m-d'));
        self::assertSame('2038-04-25', $calc->pascua(2038)->format('Y-m-d')); // la más tardía posible
    }

    public function testFeriadosDe2026CoincidenConLosDelSeed(): void
    {
        self::assertSame([
            ['fecha' => '2026-01-01', 'nombre' => 'Año Nuevo',                              'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo',                           'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo',                          'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-04-11', 'nombre' => 'Día de Juan Santamaría',                 'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-05-01', 'nombre' => 'Día Internacional del Trabajador',       'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-07-25', 'nombre' => 'Anexión del Partido de Nicoya',          'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-08-02', 'nombre' => 'Día de la Virgen de los Ángeles',        'tipo' => 'no_obligatorio_pago'],
            ['fecha' => '2026-08-15', 'nombre' => 'Día de la Madre',                        'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-08-31', 'nombre' => 'Día de la Persona Negra y la Cultura Afrocostarricense', 'tipo' => 'no_obligatorio_pago'],
            ['fecha' => '2026-09-15', 'nombre' => 'Día de la Independencia',                'tipo' => 'obligatorio_pago'],
            ['fecha' => '2026-12-01', 'nombre' => 'Día de la Abolición del Ejército',       'tipo' => 'no_obligatorio_pago'],
            ['fecha' => '2026-12-25', 'nombre' => 'Navidad',                                'tipo' => 'obligatorio_pago'],
        ], (new FeriadosLey())->delAnio(2026));
    }

    public function testSemanaSanta2027CaeEnMarzoYNoIncluyeEl12DeOctubre(): void
    {
        $fechas = array_column((new FeriadosLey())->delAnio(2027), 'fecha');

        self::assertCount(12, $fechas);
        self::assertSame(['2027-03-25', '2027-03-26'], [$fechas[1], $fechas[2]]);
        self::assertNotContains('2027-10-12', $fechas);
    }
}
