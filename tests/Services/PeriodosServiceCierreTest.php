<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\PeriodosRepository;
use App\Services\DeteccionHorasExtraService;
use App\Services\PeriodosService;
use PHPUnit\Framework\TestCase;

final class PeriodosServiceCierreTest extends TestCase
{
    private function datos(string $estado): array
    {
        return ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => $estado];
    }

    public function testCerrarRechazaLasPorJustificarDelPeriodo(): void
    {
        $repo = $this->createMock(PeriodosRepository::class);
        $repo->method('findById')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $repo->method('findOverlapping')->willReturn([]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->expects(self::once())->method('rechazarSinJustificar')->with('2026-10-01', '2026-10-15', 1, 'ip');

        (new PeriodosService($repo, $this->createMock(AuditoriaRepository::class), $deteccion))
            ->actualizar(12, $this->datos('cerrado'), 1, 'ip');
    }

    public function testDejarloAbiertoNoRechazaNada(): void
    {
        $repo = $this->createMock(PeriodosRepository::class);
        $repo->method('findById')->willReturn(['id_periodo' => 12, 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-15', 'estado' => 'abierto']);
        $repo->method('findOverlapping')->willReturn([]);
        $deteccion = $this->createMock(DeteccionHorasExtraService::class);
        $deteccion->expects(self::never())->method('rechazarSinJustificar');

        (new PeriodosService($repo, $this->createMock(AuditoriaRepository::class), $deteccion))
            ->actualizar(12, $this->datos('abierto'), 1, 'ip');
    }
}
