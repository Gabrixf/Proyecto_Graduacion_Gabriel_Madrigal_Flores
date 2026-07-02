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

    /**
     * Simula el filtrado real de SolicitudesRepository::findById: la fila
     * (dueña de $idEmpleadoReal) solo se devuelve si $idEmpleado coincide o es null.
     */
    private function findByIdEscopadoA(int $idEmpleadoReal, string $estado = 'pendiente'): \Closure
    {
        return function (int $id, ?int $idEmpleado = null) use ($idEmpleadoReal, $estado) {
            if ($idEmpleado !== null && $idEmpleado !== $idEmpleadoReal) {
                return null;
            }
            return ['id_solicitud' => $id, 'id_empleado' => $idEmpleadoReal, 'estado' => $estado];
        };
    }

    public function testObtenerLanzaExcepcionSiNoPerteneceAlEmpleado(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())
            ->method('findById')
            ->with(5, 42)
            ->willReturnCallback($this->findByIdEscopadoA(99));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solicitud no encontrada.');

        $this->service($repo)->obtener(5, 42);
    }

    public function testActualizarLanzaExcepcionSiElDuenioNoCoincide(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturnCallback($this->findByIdEscopadoA(99));
        $repo->expects(self::never())->method('update');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solicitud no encontrada.');

        $this->service($repo)->actualizar(5, ['tipo' => 'permiso'], 1, '127.0.0.1', 42);
    }

    public function testActualizarPermiteAlDuenioReal(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturnCallback($this->findByIdEscopadoA(42));
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
        $repo->method('findById')->willReturnCallback($this->findByIdEscopadoA(99));
        $repo->expects(self::never())->method('delete');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solicitud no encontrada.');

        $this->service($repo)->eliminar(5, 1, '127.0.0.1', 42);
    }

    public function testAdminSigueSinRestriccionAlNoEnviarOwnerIdEmpleado(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturnCallback($this->findByIdEscopadoA(99));
        $repo->method('countDependientes')->willReturn(0);
        $repo->expects(self::once())->method('delete');

        // Sin $ownerIdEmpleado (llamada admin): debe eliminar sin lanzar excepción.
        $this->service($repo)->eliminar(5, 1, '127.0.0.1');
    }
}
