<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\PortalRepository;
use App\Services\PortalService;
use PHPUnit\Framework\TestCase;

final class PortalServiceSaldoVacacionesAnioTest extends TestCase
{
    public function testDevuelveLaFilaDelAnioSolicitado(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('saldosVacaciones')->willReturn([
            ['anio' => 2026, 'dias_ganados' => 12.0, 'dias_disfrutados' => 3.0, 'dias_disponibles' => 9.0],
            ['anio' => 2025, 'dias_ganados' => 12.0, 'dias_disfrutados' => 12.0, 'dias_disponibles' => 0.0],
        ]);

        $service = new PortalService($repo);

        self::assertSame(9.0, $service->saldoVacacionesAnio(7, 2026)['dias_disponibles']);
    }

    public function testDevuelveNullSiNoHayFilaParaEseAnio(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('saldosVacaciones')->willReturn([
            ['anio' => 2025, 'dias_ganados' => 12.0, 'dias_disfrutados' => 12.0, 'dias_disponibles' => 0.0],
        ]);

        $service = new PortalService($repo);

        self::assertNull($service->saldoVacacionesAnio(7, 2026));
    }
}
