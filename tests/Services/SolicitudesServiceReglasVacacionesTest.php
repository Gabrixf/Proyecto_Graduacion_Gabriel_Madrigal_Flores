<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SaldoVacacionesService;
use App\Services\SolicitudesService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\DiasHabilesStub;

final class SolicitudesServiceReglasVacacionesTest extends TestCase
{
    private function service(SolicitudesRepository $repo): SolicitudesService
    {
        $empleadosRepo = $this->createMock(EmpleadosRepository::class);
        $empleadosRepo->method('findById')->willReturn(['id_empleado' => 1, 'estado' => 'activo', 'fecha_ingreso' => '2020-01-01']);
        return new SolicitudesService(
            $repo,
            $empleadosRepo,
            $this->createMock(AuditoriaRepository::class),
            $this->createMock(SaldoVacacionesService::class),
            DiasHabilesStub::servicio()
        );
    }

    /**
     * Último día hábil antes de hoy. Sin horario asignado, el domingo es descanso y un rango solo de domingo
     * no tiene días hábiles; por eso, si ayer fue domingo, se usa el sábado.
     */
    private function ayer(): string
    {
        $ayer = new DateTimeImmutable('yesterday');
        return ($ayer->format('N') === '7' ? $ayer->modify('-1 day') : $ayer)->format('Y-m-d');
    }

    /** Hoy, o el lunes si hoy es domingo (el domingo no es día hábil de vacaciones). */
    private function hoy(): string
    {
        return $this->habilDesde(new DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** Día hábil siguiente a hoy(). */
    private function manana(): string
    {
        return $this->habilDesde((new DateTimeImmutable($this->hoy()))->modify('+1 day'))->format('Y-m-d');
    }

    private function habilDesde(DateTimeImmutable $dia): DateTimeImmutable
    {
        return (int)$dia->format('N') === 7 ? $dia->modify('+1 day') : $dia;
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

    public function testCrearSinMedioDiaGuardaHorasEnCero(): void
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

        self::assertSame(0.0, $filaCapturada['horas']);   // 0 = días completos (sin NULL)
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

        self::assertSame(0.0, $filaCapturada['horas']);   // 0 = días completos (sin NULL)
    }

    public function testCrearRechazaVacacionesQueSoloCaenEnDomingo(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no incluye días hábiles');

        // 2030-11-03 es domingo.
        $this->service($repo)->crear([
            'id_empleado'  => 1,
            'tipo'         => 'vacaciones',
            'fecha_inicio' => '2030-11-03',
            'fecha_fin'    => '2030-11-03',
        ], 1, '127.0.0.1');
    }
}
