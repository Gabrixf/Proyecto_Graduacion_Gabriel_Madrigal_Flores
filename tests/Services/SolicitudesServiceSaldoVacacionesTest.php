<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Helpers\AcumulacionVacaciones;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\SolicitudesRepository;
use App\Services\SaldoVacacionesService;
use App\Services\SolicitudesService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SolicitudesServiceSaldoVacacionesTest extends TestCase
{
    private function hace(int $dias): string
    {
        return (new DateTimeImmutable('today'))->modify("-{$dias} days")->format('Y-m-d');
    }

    private function manana(): string
    {
        return (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    }

    private function pasado(): string
    {
        return (new DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
    }

    private function service(SolicitudesRepository $repo, string $fechaIngreso, float $comprometidos = 0.0): SolicitudesService
    {
        $empleadosRepo = $this->createMock(EmpleadosRepository::class);
        $empleadosRepo->method('findById')->willReturn([
            'id_empleado'   => 1,
            'estado'        => 'activo',
            'fecha_ingreso' => $fechaIngreso,
        ]);
        $repo->method('diasVacacionesComprometidos')->willReturn($comprometidos);

        return new SolicitudesService(
            $repo,
            $empleadosRepo,
            $this->createMock(AuditoriaRepository::class),
            new SaldoVacacionesService(new AcumulacionVacaciones(), $repo)
        );
    }

    /** @return array<string, mixed> */
    private function datosVacaciones(string $inicio, string $fin): array
    {
        return ['id_empleado' => 1, 'tipo' => 'vacaciones', 'fecha_inicio' => $inicio, 'fecha_fin' => $fin];
    }

    public function testCrearRechazaVacacionesDentroDelPeriodoDePrueba(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('período de prueba');

        $this->service($repo, $this->hace(10))
            ->crear($this->datosVacaciones($this->manana(), $this->manana()), 1, '127.0.0.1');
    }

    public function testCrearRechazaVacacionesQueExcedenElSaldoAcumulado(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        // ~4 meses de antigüedad → 4 días; se piden 10.
        $fin = (new DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        $this->service($repo, $this->hace(130))
            ->crear($this->datosVacaciones($this->manana(), $fin), 1, '127.0.0.1');
    }

    public function testCrearAceptaVacacionesConSaldoSuficiente(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())->method('insert')->willReturn(30);

        $id = $this->service($repo, '2020-01-01')
            ->crear($this->datosVacaciones($this->manana(), $this->pasado()), 1, '127.0.0.1');

        self::assertSame(30, $id);
    }

    public function testCrearHorasExtraNoConsultaElSaldoDeVacaciones(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->expects(self::once())->method('insert')->willReturn(31);
        $repo->expects(self::never())->method('diasVacacionesComprometidos');

        $this->service($repo, $this->hace(10))->crear([
            'id_empleado'  => 1,
            'tipo'         => 'horas_extra',
            'fecha_inicio' => $this->manana(),
            'horas'        => 2,
        ], 1, '127.0.0.1');
    }

    public function testActualizarExcluyeLaPropiaSolicitudDelSaldoComprometido(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn(['id_solicitud' => 5, 'id_empleado' => 1, 'estado' => 'pendiente']);
        $repo->expects(self::once())->method('diasVacacionesComprometidos')->with(1, 5)->willReturn(0.0);
        $repo->expects(self::once())->method('update');

        $this->service($repo, '2020-01-01')
            ->actualizar(5, $this->datosVacaciones($this->manana(), $this->pasado()), 1, '127.0.0.1');
    }

    public function testAprobarRechazaVacacionesSiYaNoHaySaldo(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 8, 'id_empleado' => 1, 'tipo' => 'vacaciones', 'estado' => 'pendiente',
            'fecha_inicio' => $this->manana(), 'fecha_fin' => $this->manana(), 'horas' => '0.00',
        ]);
        $repo->expects(self::never())->method('resolver');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Saldo de vacaciones insuficiente');

        // 4 días acumulados, ya hay 4 comprometidos por otras solicitudes.
        $this->service($repo, $this->hace(130), comprometidos: 4.0)->aprobar(8, 1, '127.0.0.1', null);
    }

    public function testAprobarVacacionesConSaldoResuelve(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 8, 'id_empleado' => 1, 'tipo' => 'vacaciones', 'estado' => 'pendiente',
            'fecha_inicio' => $this->manana(), 'fecha_fin' => $this->pasado(), 'horas' => '0.00',
        ]);
        $repo->expects(self::once())->method('resolver');

        $this->service($repo, '2020-01-01')->aprobar(8, 1, '127.0.0.1', null);
    }

    public function testAprobarUnPermisoNoConsultaElSaldoDeVacaciones(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 9, 'id_empleado' => 1, 'tipo' => 'permiso', 'estado' => 'pendiente',
            'fecha_inicio' => $this->manana(), 'fecha_fin' => $this->manana(), 'horas' => '0.00',
        ]);
        $repo->expects(self::once())->method('resolver');
        $repo->expects(self::never())->method('diasVacacionesComprometidos');

        $this->service($repo, $this->hace(10))->aprobar(9, 1, '127.0.0.1', null);
    }

    public function testRechazarNoValidaSaldo(): void
    {
        $repo = $this->createMock(SolicitudesRepository::class);
        $repo->method('findById')->willReturn([
            'id_solicitud' => 8, 'id_empleado' => 1, 'tipo' => 'vacaciones', 'estado' => 'pendiente',
            'fecha_inicio' => $this->manana(), 'fecha_fin' => $this->manana(), 'horas' => '0.00',
        ]);
        $repo->expects(self::once())->method('resolver');
        $repo->expects(self::never())->method('diasVacacionesComprometidos');

        $this->service($repo, $this->hace(10))->rechazar(8, 1, '127.0.0.1', null);
    }
}
