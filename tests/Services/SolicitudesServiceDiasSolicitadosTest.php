<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SolicitudesService;
use PHPUnit\Framework\TestCase;

final class SolicitudesServiceDiasSolicitadosTest extends TestCase
{
    private function service(): SolicitudesService
    {
        return new SolicitudesService(
            $this->createMock(SolicitudesRepository::class),
            $this->createMock(EmpleadosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testHorasExtraDevuelveNull(): void
    {
        $dias = $this->service()->diasSolicitados([
            'tipo'         => 'horas_extra',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-01',
            'horas'        => 4.0,
        ]);
        self::assertNull($dias);
    }

    public function testVacacionesConHorasSeteadoUsaEseValorComoOverride(): void
    {
        $dias = $this->service()->diasSolicitados([
            'tipo'         => 'vacaciones',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-01',
            'horas'        => 0.5,
        ]);
        self::assertSame(0.5, $dias);
    }

    public function testVacacionesSinHorasCalculaPorRangoDeFechasInclusive(): void
    {
        $dias = $this->service()->diasSolicitados([
            'tipo'         => 'vacaciones',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-05',
            'horas'        => null,
        ]);
        self::assertSame(5.0, $dias);
    }

    public function testPermisoUnDiaSinHorasDevuelveUno(): void
    {
        $dias = $this->service()->diasSolicitados([
            'tipo'         => 'permiso',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-01',
            'horas'        => null,
        ]);
        self::assertSame(1.0, $dias);
    }
}
