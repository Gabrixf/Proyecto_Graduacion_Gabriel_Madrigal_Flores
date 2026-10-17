<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SaldoVacacionesService;
use App\Services\SolicitudesService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DiasHabilesStub;

final class SolicitudesServiceDetectadasTest extends TestCase
{
    private function service(SolicitudesRepository $repo): SolicitudesService
    {
        return new SolicitudesService(
            $repo,
            $this->createMock(EmpleadosRepository::class),
            $this->createMock(AuditoriaRepository::class),
            $this->createMock(SaldoVacacionesService::class),
            DiasHabilesStub::servicio()
        );
    }

    private function repoDetectada(): SolicitudesRepository
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 70, 'id_empleado' => 5, 'tipo' => 'horas_extra', 'origen' => 'asistencia', 'estado' => 'pendiente',
        ]);
        return $repo;
    }

    public function testAprobarGenericoRechazaUnaDetectada(): void
    {
        $repo = $this->repoDetectada();
        $repo->expects(self::never())->method('resolver');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Horas extra detectadas');
        $this->service($repo)->aprobar(70, 1, 'ip', null);
    }

    public function testEdicionDeAdminRechazaUnaDetectada(): void
    {
        $repo = $this->repoDetectada();
        $repo->expects(self::never())->method('update');
        $this->expectException(RuntimeException::class);
        $this->service($repo)->obtenerEditable(70);
    }

    public function testActualizarDeAdminRechazaUnaDetectada(): void
    {
        $repo = $this->repoDetectada();
        $repo->expects(self::never())->method('update');
        $this->expectException(RuntimeException::class);
        $this->service($repo)->actualizar(70, ['tipo' => 'horas_extra'], 1, 'ip');
    }
}
