<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\ParametrosLegalesRepository;
use App\Services\ParametrosLegalesService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ParametrosLegalesServiceTest extends TestCase
{
    private const VALORES = [
        '2025' => ['ccss_obrera' => 0.1067, 'ccss_patronal' => 0.2667],
        '2026' => [
            'ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683,
            'renta_tramo_1' => 918000.0, 'renta_tramo_2' => 1347000.0, 'renta_tramo_3' => 2364000.0, 'renta_tramo_4' => 4727000.0,
            'renta_tasa_1' => 0.10, 'renta_tasa_2' => 0.15, 'renta_tasa_3' => 0.20, 'renta_tasa_4' => 0.25,
            'renta_credito_hijo' => 1710.0, 'renta_credito_conyuge' => 2590.0,
        ],
    ];

    private function service(): ParametrosLegalesService
    {
        $repo = $this->createMock(ParametrosLegalesRepository::class);
        $repo->method('valorVigente')->willReturnCallback(
            fn (string $clave, string $fecha) => self::VALORES[substr($fecha, 0, 4)][$clave] ?? null
        );
        return new ParametrosLegalesService($repo);
    }

    public function testTasasCcssSegunLaFecha(): void
    {
        self::assertSame(['ccss_obrera' => 0.1067, 'ccss_patronal' => 0.2667], $this->service()->tasasCcss('2025-12-16'));
        self::assertSame(['ccss_obrera' => 0.1083, 'ccss_patronal' => 0.2683], $this->service()->tasasCcss('2026-01-01'));
    }

    public function testTablaRenta2026(): void
    {
        $t = $this->service()->tablaRenta('2026-10-16');
        self::assertSame([
            ['desde' => 918000.0, 'tasa' => 0.10], ['desde' => 1347000.0, 'tasa' => 0.15],
            ['desde' => 2364000.0, 'tasa' => 0.20], ['desde' => 4727000.0, 'tasa' => 0.25],
        ], $t['tramos']);
        self::assertSame(1710.0, $t['credito_hijo']);
        self::assertSame(2590.0, $t['credito_conyuge']);
    }

    public function testSinRenta2025ElMensajeDiceQueFalta(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falta cargar el parámetro legal renta_tramo_1 vigente al 16/12/2025.');
        $this->service()->tablaRenta('2025-12-16');
    }
}
