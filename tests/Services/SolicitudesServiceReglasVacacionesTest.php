<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SolicitudesService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SolicitudesServiceReglasVacacionesTest extends TestCase
{
    private function service(SolicitudesRepository $repo): SolicitudesService
    {
        $empleadosRepo = $this->createMock(EmpleadosRepository::class);
        $empleadosRepo->method('findById')->willReturn(['id_empleado' => 1, 'estado' => 'activo']);
        return new SolicitudesService($repo, $empleadosRepo, $this->createMock(AuditoriaRepository::class));
    }

    private function ayer(): string
    {
        return (new DateTimeImmutable('yesterday'))->format('Y-m-d');
    }

    private function hoy(): string
    {
        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function manana(): string
    {
        return (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    }

    public function testCrearRechazaFechaInicioPasadaParaVacaciones(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La fecha de inicio no puede ser anterior a hoy.');

        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => $this->ayer(),
            'fecha_fin'    => $this->ayer(),
        ], 1, '127.0.0.1');
    }

    public function testCrearRechazaFechaInicioPasadaParaPermiso(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La fecha de inicio no puede ser anterior a hoy.');

        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'permiso',
            'fecha_inicio' => $this->ayer(),
            'fecha_fin'    => $this->ayer(),
        ], 1, '127.0.0.1');
    }

    public function testCrearPermiteFechaInicioPasadaParaHorasExtra(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())->method('insert')->willReturn(10);

        $id = $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'horas_extra',
            'fecha_inicio' => $this->ayer(),
            'horas'        => 4,
        ], 1, '127.0.0.1');

        self::assertSame(10, $id);
    }

    public function testActualizarNoRechazaFechaInicioYaPasada(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 5,
            'id_empleado'  => 1,
            'estado'       => 'pendiente',
        ]);
        $repo->expects(self::once())->method('update');

        $this->service($repo)->actualizar(5, [
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => $this->ayer(),
            'fecha_fin'    => $this->ayer(),
        ], 1, '127.0.0.1');

        $this->addToAssertionCount(1); // llegar aquí sin excepción es el resultado esperado
    }

    public function testCrearGuardaMedioDiaComoHorasCeroPuntoCinco(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $filaCapturada = null;
        $repo->expects(self::once())->method('insert')->willReturnCallback(
            function (array $fila) use (&$filaCapturada) {
                $filaCapturada = $fila;
                return 20;
            }
        );

        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => $this->hoy(),
            'fecha_fin'    => $this->hoy(),
            'medio_dia'    => '1',
        ], 1, '127.0.0.1');

        self::assertSame(0.5, $filaCapturada['horas']);
    }

    public function testCrearSinMedioDiaGuardaHorasNull(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $filaCapturada = null;
        $repo->expects(self::once())->method('insert')->willReturnCallback(
            function (array $fila) use (&$filaCapturada) {
                $filaCapturada = $fila;
                return 21;
            }
        );

        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => $this->hoy(),
            'fecha_fin'    => $this->hoy(),
        ], 1, '127.0.0.1');

        self::assertNull($filaCapturada['horas']);
    }

    public function testMedioDiaSeIgnoraSiLasFechasSonDistintas(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $filaCapturada = null;
        $repo->expects(self::once())->method('insert')->willReturnCallback(
            function (array $fila) use (&$filaCapturada) {
                $filaCapturada = $fila;
                return 22;
            }
        );

        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => $this->hoy(),
            'fecha_fin'    => $this->manana(),
            'medio_dia'    => '1',
        ], 1, '127.0.0.1');

        self::assertNull($filaCapturada['horas']);
    }
}
