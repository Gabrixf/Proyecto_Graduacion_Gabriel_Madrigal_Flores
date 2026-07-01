<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SolicitudesService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SolicitudesServiceOwnershipTest extends TestCase
{
    private function service(SolicitudesRepository $repo, ?EmpleadosRepository $empleadosRepo = null): SolicitudesService
    {
        if ($empleadosRepo === null) {
            $empleadosRepo = $this->createMock(EmpleadosRepository::class);
            $empleadosRepo->method('findById')->willReturn(['id_empleado' => 42, 'estado' => 'activo']);
        }
        return new SolicitudesService(
            $repo,
            $empleadosRepo,
            $this->createMock(AuditoriaRepository::class)
        );
    }

    public function testListarPasaIdEmpleadoAlRepositorio(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())
            ->method('findAll')
            ->with(null, 'pendiente', null, 42)
            ->willReturn([]);

        $this->service($repo)->listar(null, 'pendiente', null, 42);
    }

    public function testActualizarLanzaExcepcionSiElDuenioNoCoincide(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tiene permiso para modificar esta solicitud.');

        $this->service($repo)->actualizar(5, ['tipo' => 'permiso'], 1, '127.0.0.1', 42);
    }

    public function testActualizarPermiteAlDuenioReal(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 42, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(5, [
            'id_empleado'  => 42,
            'tipo'         => 'permiso',
            'fecha_inicio' => '2026-07-01',
            'fecha_fin'    => '2026-07-01',
        ], 1, '127.0.0.1', 42);
    }

    public function testEliminarLanzaExcepcionSiElDuenioNoCoincide(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No tiene permiso para modificar esta solicitud.');

        $this->service($repo)->eliminar(5, 1, '127.0.0.1', 42);
    }

    public function testAdminSigueSinRestriccionAlNoEnviarOwnerIdEmpleado(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5, 'id_empleado' => 99, 'estado' => 'pendiente',
        ]);
        $repo->method('countDependientes')->willReturn(0);
        $repo->expects(self::once())->method('delete');

        // Sin $ownerIdEmpleado (llamada admin): debe eliminar sin lanzar excepción.
        $this->service($repo)->eliminar(5, 1, '127.0.0.1');
    }
}
