<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\AuditoriaRepository;
use App\Repositories\EmpleadosRepository;
use App\Repositories\PeriodosRepository;
use App\Repositories\SolicitudesRepository;
use App\Repositories\VacacionesRepository;
use App\Services\VacacionesService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VacacionesServiceSaldoTest extends TestCase
{
    private function service(VacacionesRepository $repo, SolicitudesRepository $solicitudesRepo): VacacionesService
    {
        return new VacacionesService(
            $repo,
            $solicitudesRepo,
            $this->createMock(EmpleadosRepository::class),
            $this->createMock(PeriodosRepository::class),
            $this->createMock(AuditoriaRepository::class)
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function solicitudAprobada(array $overrides = []): array
    {
        return array_merge([
            'id_solicitud' => 1,
            'tipo'         => 'vacaciones',
            'estado'       => 'aprobada',
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'id_empleado'  => 7,
        ], $overrides);
    }

    public function testCrearRechazaEmpleadoSinSaldoGenerado(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('existsBySolicitud')->willReturn(false);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        $repo->method('findSaldo')->willReturn(null);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no tiene saldo de vacaciones suficiente');

        $this->service($repo, $solicitudesRepo)->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 5],
            1,
            '127.0.0.1'
        );
    }

    public function testCrearRechazaCuandoDiasSuperanSaldoDisponible(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('existsBySolicitud')->willReturn(false);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        $repo->method('findSaldo')->willReturn(['dias_disponibles' => 3.0]);
        $repo->expects(self::never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dispone de 3 día(s)');

        $this->service($repo, $solicitudesRepo)->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 5],
            1,
            '127.0.0.1'
        );
    }

    public function testCrearPermiteCuandoHaySaldoSuficiente(): void
    {
        $solicitudesRepo = $this->createMock(SolicitudesRepository::class);
        $solicitudesRepo->method('findById')->willReturn($this->solicitudAprobada());

        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('existsBySolicitud')->willReturn(false);
        $repo->method('findPeriodoAbiertoPorFecha')->willReturn(['id_periodo' => 3]);
        $repo->method('findSaldo')->willReturn(['dias_disponibles' => 10.0]);
        $repo->expects(self::once())->method('insert')->willReturn(50);

        $id = $this->service($repo, $solicitudesRepo)->crear(
            ['id_solicitud' => 1, 'dias_tomados' => 5],
            1,
            '127.0.0.1'
        );

        self::assertSame(50, $id);
    }

    public function testActualizarPermiteMismoTotalDeDiasYaRegistrados(): void
    {
        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('findById')->willReturn([
            'id_vacacion'  => 9,
            'id_empleado'  => 7,
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'dias_tomados' => 5.0,
        ]);
        // Saldo ya refleja que estos 5 días fueron descontados.
        $repo->method('findSaldo')->willReturn(['dias_disponibles' => 0.0]);
        $repo->expects(self::once())->method('update');

        $this->service($repo, $this->createMock(SolicitudesRepository::class))->actualizar(
            9,
            ['dias_tomados' => 5],
            1,
            '127.0.0.1'
        );

        $this->addToAssertionCount(1);
    }

    public function testActualizarRechazaAumentoQueSuperaElSaldo(): void
    {
        $repo = $this->createMock(VacacionesRepository::class);
        $repo->method('findById')->willReturn([
            'id_vacacion'  => 9,
            'id_empleado'  => 7,
            'fecha_inicio' => '2026-01-05',
            'fecha_fin'    => '2026-01-06',
            'dias_tomados' => 5.0,
        ]);
        $repo->method('findSaldo')->willReturn(['dias_disponibles' => 0.0]);
        $repo->expects(self::never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no tiene saldo de vacaciones suficiente');

        $this->service($repo, $this->createMock(SolicitudesRepository::class))->actualizar(
            9,
            ['dias_tomados' => 8],
            1,
            '127.0.0.1'
        );
    }
}
